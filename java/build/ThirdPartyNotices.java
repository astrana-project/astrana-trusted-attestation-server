// Writes THIRD-PARTY-NOTICES.txt from the dependencies the build resolved. Run by the build as a single-file Java
// program (see the exec-maven-plugin execution in pom.xml), with nothing on its class path but the JDK.
//
//   java ThirdPartyNotices.java <class-path-file> <local-repository> <shared-folder> <output-file>
//
// For every jar on the runtime class path that the executable jar carries, which is all but the Spring Boot
// starters, the notice gives its version, its licence, its supplier and where its
// source can be obtained, and refers to the licence and notice texts that apply to it. Those texts are the licence
// and notice files the jar itself ships, the component's own licence text where it names a licence without shipping
// it (shared/third-party/licences, chosen in component-facts.tsv), and the standard text of the licence it names. A
// component offered under a choice of licences is used under the first its project lists. Each distinct text is
// written once, after the list. The notices of components bundled into the shared inputs rather than resolved,
// Bootstrap in the stylesheet, come first, from shared/third-party/bundled. A jar with no licence text at all fails
// the build, so nothing ships unaccounted for.

import java.io.File;
import java.io.IOException;
import java.io.UncheckedIOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Comparator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.function.Function;
import java.util.jar.JarFile;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import java.util.stream.Collectors;
import java.util.stream.Stream;
import java.util.zip.ZipEntry;
import java.util.zip.ZipFile;
import javax.xml.parsers.DocumentBuilderFactory;
import org.w3c.dom.Element;
import org.w3c.dom.Node;

public class ThirdPartyNotices {

    private static final String RULE = "-".repeat(80);

    private static final Pattern LICENCE_FILE =
            Pattern.compile("(?i)^(META-INF/)?[^/]*(licen[cs]e|notice|copying)[^/]*$");

    /** The names projects give their licences in their POMs, mapped to SPDX identifiers. The first match wins. */
    private static final Map<Pattern, String> SPDX = new LinkedHashMap<>();

    static {
        SPDX.put(Pattern.compile("(?i).*apache.*2.*"), "Apache-2.0");
        SPDX.put(Pattern.compile("(?i)(the )?mit( license)?"), "MIT");
        SPDX.put(Pattern.compile("(?i)(eclipse public license|epl)\\W*(v\\W*)?2.*"), "EPL-2.0");
        SPDX.put(Pattern.compile("(?i)(eclipse public license|epl)\\W*(v\\W*)?1.*"), "EPL-1.0");
        SPDX.put(Pattern.compile("(?i)(eclipse distribution license|edl).*1.*"), "BSD-3-Clause");
        SPDX.put(Pattern.compile("(?i).*(bsd-3-clause|new bsd|revised bsd|bsd 3).*"), "BSD-3-Clause");
        SPDX.put(Pattern.compile("(?i).*(bsd-2-clause|simplified bsd|bsd 2).*"), "BSD-2-Clause");
        SPDX.put(Pattern.compile("(?i)gpl2 w/ cpe"), "GPL-2.0-only WITH Classpath-exception-2.0");
    }

    private final Path repository;
    private final Path shared;
    private final Map<String, Map<String, String>> facts;
    private final Map<String, String> labels = new LinkedHashMap<>();
    private final List<String> texts = new ArrayList<>();

    private ThirdPartyNotices(Path repository, Path shared) throws IOException {
        this.repository = repository;
        this.shared = shared;
        this.facts = readFacts(shared.resolve("third-party/component-facts.tsv"));
    }

    public static void main(String[] args) throws Exception {
        var classPath = Files.readString(Path.of(args[0])).trim();
        var notices = new ThirdPartyNotices(Path.of(args[1]), Path.of(args[2]));
        var jars = Arrays.stream(classPath.split(Pattern.quote(File.pathSeparator)))
                .filter(entry -> entry.endsWith(".jar"))
                .map(Path::of)
                .toList();

        var entries = new ArrayList<String>();
        for (var jar : jars) {
            if (!isStarter(jar)) {
                entries.add(notices.describe(jar));
            }
        }
        entries.sort(Comparator.comparing(String::toLowerCase));

        write(Path.of(args[3]), notices.compose(entries));
    }

    /** A Spring Boot starter only gathers dependencies, so the build leaves it out of the executable jar. */
    private static boolean isStarter(Path jar) throws IOException {
        try (var file = new JarFile(jar.toFile())) {
            var manifest = file.getManifest();
            return manifest != null
                    && "dependencies-starter".equals(manifest.getMainAttributes().getValue("Spring-Boot-Jar-Type"));
        }
    }

    private String describe(Path jar) throws Exception {
        // The local repository lays a jar out as <group path>/<artifactId>/<version>/<file>, so its coordinates
        // are read from where it is, whatever its POM spells with properties.
        var versionFolder = jar.getParent();
        var version = versionFolder.getFileName().toString();
        var artifactId = versionFolder.getParent().getFileName().toString();
        var groupId = repository.relativize(versionFolder.getParent().getParent()).toString().replace(File.separatorChar, '.');
        var key = groupId + ":" + artifactId;
        var pom = Pom.read(Path.of(jar.toString().replaceFirst("\\.jar$", ".pom")), repository);
        var fact = facts.getOrDefault(key, Map.of());
        var offered = pom.licences().stream().map(ThirdPartyNotices::spdx).toList();
        var licence = Optional.ofNullable(fact.get("licence")).orElse(offered.isEmpty() ? null : offered.get(0));
        var labelsOfJar = textsFor(jar, key, licence, fact);

        if (licence == null || labelsOfJar.isEmpty()) {
            throw new IllegalStateException("No licence text for " + key + " " + version
                    + ". Add its licence to shared/third-party/component-facts.tsv and, if the jar ships no copy"
                    + " of it, the text to shared/third-party/licences.");
        }

        var lines = new ArrayList<String>();
        lines.add(artifactId + " " + version + " (" + key + ")");
        lines.add("Licence: " + licence + (offered.size() > 1 && !fact.containsKey("licence")
                ? ", chosen from " + String.join(", ", pom.licences())
                : ""));
        Optional.ofNullable(fact.get("copyright")).ifPresent(lines::add);
        Optional.ofNullable(pom.organisation()).ifPresent(organisation -> lines.add("Supplier: " + organisation));
        lines.add("Source: " + Optional.ofNullable(fact.get("source"))
                .or(() -> Optional.ofNullable(pom.source()))
                .orElse("not stated by the project"));
        lines.add("Texts: " + String.join(", ", labelsOfJar));
        return String.join("\n", lines);
    }

    private List<String> textsFor(Path jar, String key, String licence, Map<String, String> fact) throws IOException {
        var found = new ArrayList<String>();
        try (var zip = new ZipFile(jar.toFile())) {
            var shipped = zip.stream()
                    .filter(entry -> !entry.isDirectory() && LICENCE_FILE.matcher(entry.getName()).matches())
                    .sorted(Comparator.comparing(ZipEntry::getName))
                    .toList();
            for (var entry : shipped) {
                var text = new String(zip.getInputStream(entry).readAllBytes(), StandardCharsets.UTF_8);
                found.add(register(text, entry.getName() + ", as shipped in " + jar.getFileName()));
            }
        }

        var licences = shared.resolve("third-party/licences");
        if (fact.containsKey("licence-file")) {
            found.add(register(Files.readString(licences.resolve(fact.get("licence-file"))), "The licence of " + key));
        } else if (licence != null && Files.exists(licences.resolve(licence + ".txt"))) {
            found.add(register(Files.readString(licences.resolve(licence + ".txt")), licence));
        }
        return found.stream().distinct().toList();
    }

    /** Numbers each distinct text in the order the notices first refer to it, and returns its number. */
    private String register(String text, String title) {
        var body = normalise(text);
        return labels.computeIfAbsent(body, added -> {
            var label = "[" + (labels.size() + 1) + "]";
            texts.add(label + " " + title + "\n\n" + added);
            return label;
        });
    }

    private String compose(List<String> entries) throws IOException {
        var attribution = Files.readString(shared.resolve("contract/attribution.json"));
        List<String> bundled;
        try (var files = Files.list(shared.resolve("third-party/bundled"))) {
            bundled = files.filter(file -> file.toString().endsWith(".txt"))
                    .sorted()
                    .map(ThirdPartyNotices::readNormalised)
                    .toList();
        }

        var output = new StringBuilder();
        output.append("THIRD-PARTY NOTICES\n\n");
        output.append(jsonString(attribution, "project_name")).append(", Java implementation\n");
        output.append(jsonString(attribution, "notice")).append("\n\n");
        output.append("""
                This software includes the third-party components listed below, each under its own licence. Each entry
                gives the component's version, its licence, its copyright notice and where its source code can be
                obtained, and refers by number to the licence and notice texts that apply to it, which follow the list
                in full. This file is generated when the software is built, from the packages the build restored.
                """);
        Stream.concat(bundled.stream(), entries.stream())
                .forEach(entry -> output.append('\n').append(RULE).append('\n').append(entry).append('\n'));
        output.append('\n').append("=".repeat(80)).append("\nLICENCE AND NOTICE TEXTS\n");
        texts.forEach(text -> output.append('\n').append(RULE).append('\n').append(text).append('\n'));
        return output.toString();
    }

    private static String spdx(String name) {
        return SPDX.entrySet().stream()
                .filter(entry -> entry.getKey().matcher(name.trim()).matches())
                .map(Map.Entry::getValue)
                .findFirst()
                .orElse(name.trim());
    }

    private static Map<String, Map<String, String>> readFacts(Path path) throws IOException {
        return Files.readAllLines(path).stream()
                .filter(line -> !line.isEmpty() && !line.startsWith("#"))
                .map(line -> line.split("\t"))
                .collect(Collectors.groupingBy(
                        fields -> fields[0], Collectors.toMap(fields -> fields[1], fields -> fields[2])));
    }

    private static String jsonString(String json, String key) {
        Matcher match = Pattern.compile("\"" + key + "\"\\s*:\\s*\"((?:[^\"\\\\]|\\\\.)*)\"").matcher(json);
        return match.find() ? match.group(1).replaceAll("\\\\(.)", "$1") : "";
    }

    private static String readNormalised(Path file) {
        try {
            return normalise(Files.readString(file));
        } catch (IOException e) {
            throw new UncheckedIOException(e);
        }
    }

    private static String normalise(String text) {
        return text.replace("\r\n", "\n").lines().map(String::stripTrailing).collect(Collectors.joining("\n"))
                .replaceAll("^[\\n\\uFEFF]+|\\n+$", "");
    }

    /** Writes only when the content changed, with a byte order mark so a browser reads the file as UTF-8. */
    private static void write(Path output, String content) throws IOException {
        var bytes = ("﻿" + content).getBytes(StandardCharsets.UTF_8);
        if (Files.exists(output) && Arrays.equals(Files.readAllBytes(output), bytes)) {
            return;
        }
        Files.createDirectories(output.getParent());
        Files.write(output, bytes);
    }

    /** What the notices need from a POM, with anything it leaves out read from its parents. */
    private record Pom(List<String> licences, String organisation, String source) {

        static Pom read(Path path, Path repository) throws Exception {
            var root = DocumentBuilderFactory.newInstance().newDocumentBuilder().parse(path.toFile()).getDocumentElement();
            var parent = child(root, "parent");
            Pom inherited = parent == null ? null : read(parentPath(parent, repository), repository);
            Function<Function<Pom, String>, String> fromParent = field -> inherited == null ? null : field.apply(inherited);

            var licences = children(child(root, "licenses"), "license").stream()
                    .map(licence -> text(licence, "name"))
                    .toList();
            return new Pom(
                    !licences.isEmpty() || inherited == null ? licences : inherited.licences(),
                    Optional.ofNullable(text(child(root, "organization"), "name"))
                            .orElseGet(() -> fromParent.apply(Pom::organisation)),
                    Optional.ofNullable(text(child(root, "scm"), "url"))
                            .filter(url -> !url.startsWith("scm:"))
                            .or(() -> Optional.ofNullable(text(root, "url")))
                            .orElseGet(() -> fromParent.apply(Pom::source)));
        }

        private static Path parentPath(Element parent, Path repository) {
            var group = text(parent, "groupId");
            var artifact = text(parent, "artifactId");
            var version = text(parent, "version");
            return repository.resolve(group.replace('.', '/')).resolve(artifact).resolve(version)
                    .resolve(artifact + "-" + version + ".pom");
        }

        private static Element child(Element element, String name) {
            return element == null ? null : children(element, name).stream().findFirst().orElse(null);
        }

        private static List<Element> children(Element element, String name) {
            var found = new ArrayList<Element>();
            if (element == null) {
                return found;
            }
            for (Node node = element.getFirstChild(); node != null; node = node.getNextSibling()) {
                if (node instanceof Element child && child.getTagName().equals(name)) {
                    found.add(child);
                }
            }
            return found;
        }

        private static String text(Element element, String name) {
            var child = child(element, name);
            var value = child == null ? "" : child.getTextContent().trim();
            return value.isEmpty() || value.contains("${") ? null : value;
        }
    }
}
