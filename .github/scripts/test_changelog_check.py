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
        self.assertTrue(check.released_content("java/pom.xml", "<project/>", "<project/>\n"))


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

    def test_a_served_shared_file_is_a_shared_change(self):
        shared, impls = check.released_changes(["shared/ui/ui-strings.json"], lambda p: "", lambda p: "")
        self.assertEqual((shared, impls), (True, set()))


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

    def test_a_served_shared_file_still_needs_a_version(self):
        self.write("shared/ui/ui-strings.json", '{"a": "b"}\n')
        self.commit()
        code, output = self.run_check()
        self.assertEqual(code, 1, output)
        self.assertIn("a shared change needs a new MINOR or MAJOR version", output)

    def test_a_code_change_still_needs_a_patch_version(self):
        self.write("dotnet/src/Program.cs", "// changed\n")
        self.commit()
        self.assertEqual(self.run_check()[0], 1)
        self.write("dotnet/CHANGELOG.md", "# Changelog\n\n## [1.0.1] - 2026-10-06\n\n## [1.0.0] - 2026-10-05\n")
        self.commit()
        self.assertEqual(self.run_check()[0], 0)


if __name__ == "__main__":
    unittest.main()
