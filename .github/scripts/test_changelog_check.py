"""Tests for changelog_check.py. Run from the repository root with `python -m unittest discover -s .github/scripts`."""

import json
import os
import pathlib
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

import changelog_check as check  # noqa: E402

SCRIPT = pathlib.Path(__file__).resolve().parent / "changelog_check.py"
CSPROJ = "dotnet/src/Astrana.TrustedAttestation.Server/Astrana.TrustedAttestation.Server.csproj"
LOCK = "dotnet/src/Astrana.TrustedAttestation.Server/packages.lock.json"
POM = "java/pom.xml"

BASE_CSPROJ = """\ufeff<Project Sdk="Microsoft.NET.Sdk.Web">

  <PropertyGroup>
    <TargetFramework>net10.0</TargetFramework>
    <UserSecretsId>astrana-trusted-attestation-server</UserSecretsId>
  </PropertyGroup>

  <ItemGroup>
    <PackageReference Include="Sustainsys.Saml2.AspNetCore2" Version="2.11.0" />
  </ItemGroup>

</Project>
"""


BASE_POM = """<project>
  <parent>
    <groupId>org.springframework.boot</groupId>
    <artifactId>spring-boot-starter-parent</artifactId>
    <version>4.0.0</version>
  </parent>
  <dependencies>
    <dependency>
      <groupId>org.bouncycastle</groupId>
      <artifactId>bcprov-jdk18on</artifactId>
      <version>1.82</version>
    </dependency>
    <dependency>
      <groupId>com.h2database</groupId>
      <artifactId>h2</artifactId>
      <version>2.4.240</version>
      <scope>test</scope>
    </dependency>
  </dependencies>
  <build>
    <plugins>
      <plugin>
        <groupId>org.springframework.boot</groupId>
        <artifactId>spring-boot-maven-plugin</artifactId>
        <configuration>
          <includeTools>false</includeTools>
        </configuration>
      </plugin>
      <!-- Coverage. -->
      <plugin>
        <groupId>org.jacoco</groupId>
        <artifactId>jacoco-maven-plugin</artifactId>
        <version>0.8.13</version>
        <executions>
          <execution>
            <id>prepare-agent</id>
            <goals>
              <goal>prepare-agent</goal>
            </goals>
          </execution>
        </executions>
      </plugin>
      <plugin>
        <groupId>com.diffplug.spotless</groupId>
        <artifactId>spotless-maven-plugin</artifactId>
        <version>3.10.3</version>
      </plugin>
    </plugins>
  </build>
</project>
"""


COMPOSER_JSON = "php/composer.json"
COMPOSER_LOCK = "php/composer.lock"


def composer_json(require: dict, require_dev: dict) -> str:
    return json.dumps({"name": "astrana/trusted-attestation-server", "require": require, "require-dev": require_dev})


def composer_lock(packages: dict, packages_dev: dict, content_hash: str = "a") -> str:
    def entries(versions: dict) -> list:
        return [{"name": name, "version": version} for name, version in versions.items()]

    return json.dumps(
        {"content-hash": content_hash, "packages": entries(packages), "packages-dev": entries(packages_dev)}, indent=4
    )


def lock(**packages: str) -> str:
    entries = {name: {"type": "Direct", "resolved": version, "contentHash": "a"} for name, version in packages.items()}
    return json.dumps({"version": 1, "dependencies": {"net10.0": entries, "net10.0/linux-x64": {}}}, indent=2)


class ReleasedCode(unittest.TestCase):
    def test_application_code_and_served_shared_files_are_released(self):
        for path in (
            "dotnet/src/Astrana.TrustedAttestation.Server/Program.cs",
            "java/src/main/java/com/astrana/Thing.java",
            "java/pom.xml",
            "php/app/Http/Controllers/PageController.php",
            "php/composer.json",
            "php/composer.lock",
            "php/scripts/a-script-nobody-has-classified.php",
            "dotnet/build/ThirdPartyNotices.targets",
            "shared/contract/attribution.json",
            "shared/schema/schema-mysql.sql",
            "shared/ui/dist/trusted-attestation.css",
            "shared/ui/favicon.svg",
            "shared/ui/theme-overrides.css",
            "shared/ui/ui-strings.json",
            CSPROJ,
            LOCK,
        ):
            with self.subTest(path=path):
                self.assertTrue(check.released_code(path))

    def test_files_that_never_ship_are_not_released(self):
        for path in (
            "dotnet/Dockerfile",
            "java/Dockerfile",
            "php/Dockerfile",
            "shared/ui/package.json",
            "shared/ui/package-lock.json",
            "shared/ui/src/trusted-attestation.scss",
            "php/scripts/add-native-sbom-components.php",
            "php/scripts/generate-third-party-notices.php",
            "php/scripts/sync-shared-assets.php",
            "shared/ui/README.md",
            "php/tests/Unit/NativeSbomComponentsTest.php",
            "dotnet/demo/compose.yml",
            "java/src/main/resources/application-dev.yml",
            "shared/test/ci/Dockerfile.suite",
            "dotnet/sbom.json",
            "java/sbom.json",
            "php/sbom.json",
            "scripts/setup.sh",
            ".github/workflows/release.yml",
        ):
            with self.subTest(path=path):
                self.assertFalse(check.released_code(path))


class LockFile(unittest.TestCase):
    def test_a_new_lock_file_is_not_released(self):
        self.assertFalse(check.released_content(LOCK, "", lock(Serilog="4.0.0")))

    def test_a_lock_file_with_the_same_resolved_versions_is_not_released(self):
        reformatted = json.dumps(json.loads(lock(Serilog="4.0.0")))
        self.assertFalse(check.released_content(LOCK, lock(Serilog="4.0.0"), reformatted))

    def test_a_changed_resolved_version_is_released(self):
        self.assertTrue(check.released_content(LOCK, lock(Serilog="4.0.0"), lock(Serilog="4.0.1")))

    def test_an_added_package_is_released(self):
        self.assertTrue(check.released_content(LOCK, lock(Serilog="4.0.0"), lock(Serilog="4.0.0", Polly="8.0.0")))

    def test_a_removed_lock_file_is_released(self):
        self.assertTrue(check.released_content(LOCK, lock(Serilog="4.0.0"), ""))

    def test_a_lock_file_that_does_not_parse_is_released(self):
        self.assertTrue(check.released_content(LOCK, lock(Serilog="4.0.0"), "{ not json"))


class ProjectFile(unittest.TestCase):
    def test_restore_settings_and_comments_are_not_released(self):
        head = BASE_CSPROJ.replace(
            "    <UserSecretsId>astrana-trusted-attestation-server</UserSecretsId>\n",
            "    <UserSecretsId>astrana-trusted-attestation-server</UserSecretsId>\n"
            "    <!-- packages.lock.json records the exact version of every package,\n"
            "         across two lines of comment. -->\n"
            "    <RestorePackagesWithLockFile>true</RestorePackagesWithLockFile>\n"
            "    <RestoreLockedMode Condition=\"'$(CI)' == 'true'\">true</RestoreLockedMode>\n"
            "    <RuntimeIdentifiers>linux-x64;linux-arm64</RuntimeIdentifiers>\n",
        )
        self.assertFalse(check.released_content(CSPROJ, BASE_CSPROJ, head))

    def test_a_property_group_holding_only_restore_settings_is_not_released(self):
        head = BASE_CSPROJ.replace(
            "  <ItemGroup>",
            "  <PropertyGroup>\n    <RestoreLockedMode>true</RestoreLockedMode>\n  </PropertyGroup>\n\n  <ItemGroup>",
        )
        self.assertFalse(check.released_content(CSPROJ, BASE_CSPROJ, head))

    def test_a_byte_order_mark_and_line_endings_are_not_released(self):
        head = BASE_CSPROJ.lstrip("\ufeff").replace("\n", "\r\n")
        self.assertFalse(check.released_content(CSPROJ, BASE_CSPROJ, head))

    def test_a_package_version_change_is_released(self):
        head = BASE_CSPROJ.replace('Version="2.11.0"', 'Version="2.11.1"')
        self.assertTrue(check.released_content(CSPROJ, BASE_CSPROJ, head))

    def test_another_property_change_is_released(self):
        head = BASE_CSPROJ.replace("net10.0", "net11.0")
        self.assertTrue(check.released_content(CSPROJ, BASE_CSPROJ, head))

    def test_a_new_project_file_is_released(self):
        self.assertTrue(check.released_content(CSPROJ, "", BASE_CSPROJ))

    def test_other_files_are_released_whatever_changed(self):
        self.assertTrue(check.released_content("php/app/Providers/AppServiceProvider.php", "<?php", "<?php\n"))


class ComposerFiles(unittest.TestCase):
    BASE_JSON = composer_json({"php": "^8.4"}, {"phpunit/phpunit": "^12.0"})
    BASE_LOCK = composer_lock({"laravel/framework": "v12.0.0"}, {"phpunit/phpunit": "12.0.0"})

    def test_a_development_requirement_is_not_released(self):
        head = composer_json({"php": "^8.4"}, {"phpunit/phpunit": "^12.1", "mockery/mockery": "^1.6"})
        self.assertFalse(check.released_content(COMPOSER_JSON, self.BASE_JSON, head))

    def test_a_requirement_is_released(self):
        head = composer_json({"php": "^8.4", "guzzlehttp/guzzle": "^7.0"}, {"phpunit/phpunit": "^12.0"})
        self.assertTrue(check.released_content(COMPOSER_JSON, self.BASE_JSON, head))

    def test_development_packages_and_the_content_hash_are_not_released(self):
        head = composer_lock({"laravel/framework": "v12.0.0"}, {"phpunit/phpunit": "12.1.0"}, content_hash="b")
        self.assertFalse(check.released_content(COMPOSER_LOCK, self.BASE_LOCK, head))

    def test_a_package_version_change_is_released(self):
        head = composer_lock({"laravel/framework": "v12.0.1"}, {"phpunit/phpunit": "12.0.0"}, content_hash="b")
        self.assertTrue(check.released_content(COMPOSER_LOCK, self.BASE_LOCK, head))

    def test_a_composer_file_that_does_not_parse_is_released(self):
        self.assertTrue(check.released_content(COMPOSER_JSON, self.BASE_JSON, "{ not json"))
        self.assertTrue(check.released_content(COMPOSER_LOCK, self.BASE_LOCK, "{ not json"))

    def test_a_new_or_removed_composer_file_is_released(self):
        self.assertTrue(check.released_content(COMPOSER_LOCK, "", self.BASE_LOCK))
        self.assertTrue(check.released_content(COMPOSER_JSON, self.BASE_JSON, ""))


class ProjectObjectModel(unittest.TestCase):
    def test_jacoco_configuration_is_not_released(self):
        head = BASE_POM.replace(
            "</goals>\n          </execution>",
            "</goals>\n            <configuration>\n              <append>false</append>\n"
            "              <includes>\n                <include>org/astrana/**</include>\n"
            "              </includes>\n            </configuration>\n          </execution>",
        )
        self.assertNotEqual(head, BASE_POM)
        self.assertFalse(check.released_content(POM, BASE_POM, head))

    def test_a_build_only_plugin_version_and_a_comment_are_not_released(self):
        head = BASE_POM.replace("3.10.3", "3.11.0").replace("<!-- Coverage. -->", "<!-- Coverage, measured. -->")
        self.assertFalse(check.released_content(POM, BASE_POM, head))

    def test_jacoco_offline_instrumentation_is_released(self):
        head = BASE_POM.replace("<goal>prepare-agent</goal>", "<goal>instrument</goal>")
        self.assertTrue(check.released_content(POM, BASE_POM, head))

    def test_a_dependency_version_change_is_released(self):
        self.assertTrue(check.released_content(POM, BASE_POM, BASE_POM.replace("1.82", "1.83")))

    def test_a_test_scope_dependency_is_not_released(self):
        added = (
            "    <dependency>\n      <groupId>org.mockito</groupId>\n      <artifactId>mockito-core</artifactId>\n"
            "      <scope>test</scope>\n    </dependency>\n  </dependencies>"
        )
        head = BASE_POM.replace("2.4.240", "2.4.241").replace("  </dependencies>", added)
        self.assertFalse(check.released_content(POM, BASE_POM, head))

    def test_a_dependency_moved_out_of_the_test_scope_is_released(self):
        head = BASE_POM.replace("      <scope>test</scope>\n", "")
        self.assertTrue(check.released_content(POM, BASE_POM, head))

    def test_a_parent_version_change_is_released(self):
        self.assertTrue(check.released_content(POM, BASE_POM, BASE_POM.replace("4.0.0", "4.0.1")))

    def test_a_plugin_that_shapes_the_jar_is_released(self):
        head = BASE_POM.replace("<includeTools>false</includeTools>", "<includeTools>true</includeTools>")
        self.assertTrue(check.released_content(POM, BASE_POM, head))

    def test_a_new_project_object_model_is_released(self):
        self.assertTrue(check.released_content(POM, "", BASE_POM))


class ReleasedChanges(unittest.TestCase):
    def test_pull_request_three_releases_nothing(self):
        files = [
            "dotnet/Dockerfile",
            CSPROJ,
            LOCK,
            "dotnet/tests/Astrana.TrustedAttestation.Server.Tests/packages.lock.json",
            "java/Dockerfile",
            "php/Dockerfile",
            "php/scripts/add-native-sbom-components.php",
            "php/tests/Unit/NativeSbomComponentsTest.php",
            "shared/test/ci/suite-requirements.txt",
            "shared/ui/README.md",
            "shared/ui/package-lock.json",
        ]
        head_csproj = BASE_CSPROJ.replace(
            "  </PropertyGroup>",
            "    <RestorePackagesWithLockFile>true</RestorePackagesWithLockFile>\n  </PropertyGroup>",
        )
        at_base = {CSPROJ: BASE_CSPROJ}.get
        at_head = {CSPROJ: head_csproj, LOCK: lock(Serilog="4.0.0")}.get
        self.assertEqual(
            check.released_changes(files, lambda p: at_base(p, ""), lambda p: at_head(p, "")), (False, set())
        )

    def test_a_dependency_update_releases_that_implementation(self):
        files = [LOCK, "php/composer.lock"]
        at_base = {LOCK: lock(Serilog="4.0.0")}.get
        at_head = {LOCK: lock(Serilog="4.0.1")}.get
        self.assertEqual(
            check.released_changes(files, lambda p: at_base(p, ""), lambda p: at_head(p, "")),
            (False, {"dotnet", "php"}),
        )

    def test_a_build_plugin_change_and_regenerated_bills_of_materials_release_nothing(self):
        files = [POM, "dotnet/sbom.json", "java/sbom.json", "php/sbom.json"]
        head_pom = BASE_POM.replace("3.10.3", "3.11.0")
        self.assertEqual(
            check.released_changes(files, {POM: BASE_POM}.get, lambda p: {POM: head_pom}.get(p, "")), (False, set())
        )

    def test_a_development_only_dependency_update_releases_nothing(self):
        files = [COMPOSER_JSON, COMPOSER_LOCK, POM]
        at_base = {
            COMPOSER_JSON: composer_json({"php": "^8.4"}, {"phpunit/phpunit": "^12.0"}),
            COMPOSER_LOCK: composer_lock({"laravel/framework": "v12.0.0"}, {"phpunit/phpunit": "12.0.0"}),
            POM: BASE_POM,
        }
        at_head = {
            COMPOSER_JSON: composer_json({"php": "^8.4"}, {"phpunit/phpunit": "^12.1"}),
            COMPOSER_LOCK: composer_lock({"laravel/framework": "v12.0.0"}, {"phpunit/phpunit": "12.1.0"}, "b"),
            POM: BASE_POM.replace("2.4.240", "2.4.241"),
        }
        self.assertEqual(check.released_changes(files, at_base.get, at_head.get), (False, set()))

    def test_a_served_shared_file_is_a_shared_change(self):
        shared, impls = check.released_changes(["shared/ui/ui-strings.json"], lambda p: "", lambda p: "")
        self.assertEqual((shared, impls), (True, set()))


def stated_pom(version: str) -> str:
    """BASE_POM in Maven's namespace with the project's own version, beside the parent's and a dependency's."""
    declaration = '<?xml version="1.0" encoding="UTF-8"?>\n<project xmlns="http://maven.apache.org/POM/4.0.0">'
    own = f"  </parent>\n  <groupId>org.astrana</groupId>\n  <version>{version}</version>\n"
    return BASE_POM.replace("<project>", declaration).replace("  </parent>\n", own)


def sbom(impl: str, version: str, ref_version: str | None = None, purl_version: str | None = None) -> str:
    """A bill of materials whose metadata.component is written the way each implementation's generator writes it."""
    ref, purl = ref_version or version, purl_version or ref_version or version
    component = {
        "dotnet": {
            "name": "Astrana.TrustedAttestation",
            "version": version,
            "bom-ref": f"Astrana.TrustedAttestation@{ref}",
        },
        "java": {
            "group": "org.astrana",
            "name": "trusted-attestation-server",
            "version": version,
            "bom-ref": f"pkg:maven/org.astrana/trusted-attestation-server@{ref}?type=jar",
            "purl": f"pkg:maven/org.astrana/trusted-attestation-server@{purl}?type=jar",
        },
        "php": {
            "group": "astrana",
            "name": "trusted-attestation-server",
            "version": version,
            "bom-ref": f"astrana/trusted-attestation-server-{ref}.0",
            "purl": f"pkg:composer/astrana/trusted-attestation-server@{purl}",
        },
    }[impl]
    return json.dumps({"bomFormat": "CycloneDX", "metadata": {"component": {"type": "application", **component}}})


class StatedVersions(unittest.TestCase):
    HEAD = {"dotnet": (1, 0, 2, "2026-10-06"), "java": (1, 0, 1, "2026-10-06"), "php": (1, 0, 3, "2026-10-06")}

    def files(self, **overrides):
        files = {
            POM: stated_pom("1.0.1"),
            "dotnet/sbom.json": sbom("dotnet", "1.0.2"),
            "java/sbom.json": sbom("java", "1.0.1"),
            "php/sbom.json": sbom("php", "1.0.3"),
        }
        files.update(overrides)
        return lambda path: files.get(path, "")

    def test_versions_that_agree_with_the_changelogs_pass(self):
        self.assertEqual(check.stated_version_errors(self.HEAD, self.files()), [])

    def test_the_parent_and_dependency_versions_are_ignored(self):
        pom = stated_pom("1.0.1")
        self.assertIn("<version>4.0.0</version>", pom)
        self.assertIn("<version>1.82</version>", pom)
        self.assertEqual(check.stated_version_errors(self.HEAD, self.files(**{POM: pom})), [])

    def test_a_pom_left_behind_fails(self):
        errors = check.stated_version_errors(self.HEAD, self.files(**{POM: stated_pom("1.0.0")}))
        self.assertEqual(
            errors, ["java/pom.xml: the project <version> is 1.0.0, expected 1.0.1 from java/CHANGELOG.md."]
        )

    def test_an_sbom_left_behind_fails(self):
        behind = sbom("dotnet", "1.0.1", "1.0.2")
        errors = check.stated_version_errors(self.HEAD, self.files(**{"dotnet/sbom.json": behind}))
        self.assertEqual(
            errors, ["dotnet/sbom.json: metadata.component.version is 1.0.1, expected 1.0.2 from dotnet/CHANGELOG.md."]
        )

    def test_a_bom_ref_left_behind_fails(self):
        behind = sbom("php", "1.0.3", ref_version="1.0.2", purl_version="1.0.3")
        errors = check.stated_version_errors(self.HEAD, self.files(**{"php/sbom.json": behind}))
        self.assertEqual(
            errors,
            [
                "php/sbom.json: metadata.component.bom-ref is astrana/trusted-attestation-server-1.0.2.0, expected "
                "astrana/trusted-attestation-server-1.0.3.0 from php/CHANGELOG.md."
            ],
        )

    def test_a_purl_left_behind_fails(self):
        behind = sbom("java", "1.0.1", ref_version="1.0.1", purl_version="1.0.0")
        errors = check.stated_version_errors(self.HEAD, self.files(**{"java/sbom.json": behind}))
        self.assertEqual(len(errors), 1)
        self.assertIn(
            "java/sbom.json: metadata.component.purl is pkg:maven/org.astrana/trusted-attestation-server@1.0.0",
            errors[0],
        )

    def test_a_file_that_does_not_parse_fails(self):
        errors = check.stated_version_errors(self.HEAD, self.files(**{"java/sbom.json": "{ not json"}))
        self.assertEqual(errors, ["java/sbom.json: could not be read to compare its version with java/CHANGELOG.md."])

    def test_an_implementation_without_a_version_is_not_compared(self):
        head = {**self.HEAD, "java": None}
        self.assertEqual(check.stated_version_errors(head, self.files(**{POM: stated_pom("9.9.9")})), [])


class WholeCheck(unittest.TestCase):
    """Runs the script against a throwaway repository, isolated from the developer's Git configuration."""

    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.folder.name)
        empty_config = self.root / "empty.gitconfig"
        empty_config.write_bytes(b"")
        self.env = {
            **os.environ,
            "GIT_CONFIG_GLOBAL": str(empty_config),
            "GIT_CONFIG_NOSYSTEM": "1",
            "GIT_AUTHOR_NAME": "Test",
            "GIT_AUTHOR_EMAIL": "test@example.invalid",
            "GIT_COMMITTER_NAME": "Test",
            "GIT_COMMITTER_EMAIL": "test@example.invalid",
        }
        self.repo = self.root / "repo"
        self.repo.mkdir()
        self.git("init", "-q", "-b", "master")
        for impl in check.IMPLS:
            self.write(f"{impl}/CHANGELOG.md", "# Changelog\n\n## [1.0.0] - 2026-10-05\n")
            self.write(f"{impl}/Dockerfile", "FROM scratch\n")
        self.write("dotnet/src/Program.cs", "// program\n")
        self.write("shared/ui/ui-strings.json", "{}\n")
        self.commit()
        self.git("checkout", "-q", "-b", "change")

    def tearDown(self):
        self.folder.cleanup()

    def git(self, *args):
        subprocess.run(["git", *args], cwd=self.repo, env=self.env, check=True, capture_output=True)

    def write(self, path, text):
        target = self.repo / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(text.encode())

    def commit(self):
        self.git("add", "-A")
        self.git("commit", "-q", "-m", "change")

    def run_check(self):
        result = subprocess.run(
            [sys.executable, str(SCRIPT), "master"], cwd=self.repo, env=self.env, capture_output=True, text=True
        )
        return result.returncode, result.stdout

    def test_a_dockerfile_change_needs_no_version(self):
        for impl in check.IMPLS:
            self.write(f"{impl}/Dockerfile", "FROM scratch@sha256:0\n")
        self.commit()
        self.assertEqual(self.run_check()[0], 0)

    def release(self, **versions):
        """Adds a dated version above each named implementation's current one, which stays as the base below it."""
        for impl, version in versions.items():
            text = (self.repo / f"{impl}/CHANGELOG.md").read_bytes().decode()
            self.write(f"{impl}/CHANGELOG.md", text.replace("\n\n## [", f"\n\n## [{version}] - 2026-10-06\n\n## [", 1))

    def start_from(self, **versions):
        """Releases these versions on master and starts the change branch again from there."""
        self.git("checkout", "-q", "master")
        self.release(**versions)
        self.commit()
        self.git("checkout", "-q", "-B", "change")

    def change_shared_file(self):
        self.write("shared/ui/ui-strings.json", '{"a": "b"}\n')

    def test_a_served_shared_file_still_needs_a_version(self):
        self.change_shared_file()
        self.commit()
        code, output = self.run_check()
        self.assertEqual(code, 1, output)
        self.assertIn("a shared change needs a new version in all three changelogs", output)

    def test_a_shared_change_may_bump_the_same_minor_in_all_three(self):
        self.start_from(dotnet="1.0.2", php="1.0.1")
        self.change_shared_file()
        self.release(dotnet="1.1.0", java="1.1.0", php="1.1.0")
        self.commit()
        code, output = self.run_check()
        self.assertEqual(code, 0, output)

    def test_a_shared_fix_may_bump_each_patch_from_its_own_version(self):
        self.start_from(dotnet="1.0.2", php="1.0.1")
        self.change_shared_file()
        self.release(dotnet="1.0.3", java="1.0.1", php="1.0.2")
        self.commit()
        code, output = self.run_check()
        self.assertEqual(code, 0, output)

    def test_a_shared_fix_steps_each_patch_from_its_own_version_not_another(self):
        self.start_from(dotnet="1.0.2", php="1.0.1")
        self.change_shared_file()
        self.release(dotnet="1.0.3", java="1.0.3", php="1.0.3")
        self.commit()
        code, output = self.run_check()
        self.assertEqual(code, 1, output)
        self.assertIn("java/CHANGELOG.md: 1.0.0 to 1.0.3 is not a single", output)

    def test_a_shared_fix_bumps_all_three(self):
        self.change_shared_file()
        self.release(dotnet="1.0.1", java="1.0.1")
        self.commit()
        code, output = self.run_check()
        self.assertEqual(code, 1, output)
        self.assertIn("php/CHANGELOG.md: a shared change needs a new version in all three changelogs", output)

    def test_a_shared_change_does_not_mix_patch_and_minor(self):
        self.change_shared_file()
        self.release(dotnet="1.0.1", java="1.1.0", php="1.1.0")
        self.commit()
        code, output = self.run_check()
        self.assertEqual(code, 1, output)
        self.assertIn("not a mix", output)

    def test_a_shared_feature_writes_the_same_version_to_all_three(self):
        self.change_shared_file()
        self.release(dotnet="1.1.0", java="1.1.0", php="2.0.0")
        self.commit()
        code, output = self.run_check()
        self.assertEqual(code, 1, output)
        self.assertIn("MAJOR.MINOR must be equal", output)
        self.assertIn("writes the same version to all three changelogs", output)

    def state_versions(self, **versions):
        for impl, version in versions.items():
            self.write(f"{impl}/sbom.json", sbom(impl, version))
            if impl == "java":
                self.write(POM, stated_pom(version))

    def start_with_stated_versions(self):
        self.git("checkout", "-q", "master")
        self.state_versions(dotnet="1.0.0", java="1.0.0", php="1.0.0")
        self.commit()
        self.git("checkout", "-q", "-B", "change")

    def test_a_release_sets_the_version_in_the_bill_of_materials(self):
        self.start_with_stated_versions()
        self.write("dotnet/src/Program.cs", "// changed\n")
        self.release(dotnet="1.0.1")
        self.commit()
        code, output = self.run_check()
        self.assertEqual(code, 1, output)
        self.assertIn("dotnet/sbom.json: metadata.component.version is 1.0.0, expected 1.0.1", output)
        self.state_versions(dotnet="1.0.1")
        self.commit()
        code, output = self.run_check()
        self.assertEqual(code, 0, output)

    def test_setting_the_stated_versions_is_not_a_release_by_itself(self):
        self.start_with_stated_versions()
        self.state_versions(java="1.0.1")
        self.release(java="1.0.1")
        self.commit()
        code, output = self.run_check()
        self.assertEqual(code, 1, output)
        self.assertIn("java/CHANGELOG.md: version changed but nothing released", output)

    def test_a_code_change_still_needs_a_patch_version(self):
        self.write("dotnet/src/Program.cs", "// changed\n")
        self.commit()
        self.assertEqual(self.run_check()[0], 1)
        self.write("dotnet/CHANGELOG.md", "# Changelog\n\n## [1.0.1] - 2026-10-06\n\n## [1.0.0] - 2026-10-05\n")
        self.commit()
        self.assertEqual(self.run_check()[0], 0)


if __name__ == "__main__":
    unittest.main()
