// Writes THIRD-PARTY-NOTICES.txt from the packages the build restored. Compiled and run by MSBuild as an inline task
// (see ThirdPartyNotices.targets), so it is limited to the .NET Standard 2.0 API that inline tasks are built against.
//
// For every package a file of which ships with the server, the notice gives its version, its licence, its copyright
// notice and where its source can be obtained, and refers to the licence and notice texts that apply to it. Those
// texts are the licence and notice files the package itself ships, the component's own licence text where the
// package names a licence without shipping it (shared/third-party/licences, chosen in component-facts.tsv), and the
// standard text of the licence the package names. Each distinct text is written once, after the list. The notices
// of components bundled into the shared inputs rather than restored, Bootstrap in the stylesheet, come first, from
// shared/third-party/bundled. A package with no licence text at all fails the build, so nothing ships unaccounted for.
using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text;
using System.Text.RegularExpressions;
using System.Xml.Linq;
using Microsoft.Build.Framework;
using Microsoft.Build.Utilities;

public class WriteThirdPartyNotices : Task
{
    private const string Rule = "--------------------------------------------------------------------------------";

    private static readonly Regex LicenceFileName = new Regex("licen[cs]e|notice", RegexOptions.IgnoreCase);

    /// <summary>The files the build copies from packages, each carrying NuGetPackageId and NuGetPackageVersion.</summary>
    [Required]
    public ITaskItem[] Packages { get; set; }

    /// <summary>The folder NuGet restored the packages into.</summary>
    [Required]
    public string PackageRoot { get; set; }

    /// <summary>The repository's shared folder.</summary>
    [Required]
    public string SharedDirectory { get; set; }

    /// <summary>The implementation's name, as the notices' heading shows it.</summary>
    [Required]
    public string Implementation { get; set; }

    [Required]
    public string OutputFile { get; set; }

    public override bool Execute()
    {
        var facts = ComponentFacts.Read(Path.Combine(SharedDirectory, "third-party", "component-facts.tsv"));
        var texts = new TextRegistry();
        var entries = new List<string>();

        foreach (var package in ShippedPackages())
        {
            var entry = Describe(package.Id, package.Version, facts, texts);
            if (entry == null)
            {
                return false;
            }

            entries.Add(entry);
        }

        WriteIfChanged(Compose(entries, texts));
        return true;
    }

    private IEnumerable<(string Id, string Version)> ShippedPackages() =>
        Packages
            .Select(item => (Id: item.GetMetadata("NuGetPackageId"), Version: item.GetMetadata("NuGetPackageVersion")))
            .Where(package => package.Id.Length > 0)
            .Distinct()
            .OrderBy(package => package.Id, StringComparer.OrdinalIgnoreCase);

    private string Describe(string id, string version, ComponentFacts facts, TextRegistry texts)
    {
        var folder = Path.Combine(PackageRoot, id.ToLowerInvariant(), version.ToLowerInvariant());
        var nuspec = Nuspec.Read(Path.Combine(folder, id.ToLowerInvariant() + ".nuspec"));
        var licence = facts.Get(id, "licence") ?? nuspec.LicenceExpression ?? facts.Get(id, "licence-name");
        var labels = TextsFor(id, version, folder, nuspec, licence, facts, texts);

        if (licence == null || labels.Count == 0)
        {
            Log.LogError(
                "No licence text for {0} {1}. Add its licence to shared/third-party/component-facts.tsv and, if the "
                + "package ships no copy of it, the text to shared/third-party/licences.", id, version);
            return null;
        }

        var lines = new List<string> { id + " " + version, "Licence: " + licence };
        var copyright = facts.Get(id, "copyright") ?? nuspec.Copyright;
        lines.Add(copyright != null ? CopyrightLine(copyright) : "Authors: " + nuspec.Authors);
        lines.Add("Source: " + (facts.Get(id, "source") ?? nuspec.Source ?? "not stated by the package"));
        lines.Add("Texts: " + string.Join(", ", labels));
        return string.Join("\n", lines);
    }

    private List<string> TextsFor(
        string id, string version, string folder, Nuspec nuspec, string licence, ComponentFacts facts, TextRegistry texts)
    {
        var shipped = Directory.GetFiles(folder)
            .Where(file => LicenceFileName.IsMatch(Path.GetFileName(file)) && !file.EndsWith(".nupkg", StringComparison.OrdinalIgnoreCase))
            .Concat(nuspec.LicenceFile != null ? new[] { Path.Combine(folder, nuspec.LicenceFile) } : new string[0])
            .Select(Path.GetFullPath)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .OrderBy(file => file, StringComparer.Ordinal);

        var labels = shipped
            .Select(file => texts.Add(File.ReadAllText(file), Path.GetFileName(file) + ", as shipped in " + id + " " + version))
            .ToList();

        var licences = Path.Combine(SharedDirectory, "third-party", "licences");
        var ownText = facts.Get(id, "licence-file");
        var standardText = licence != null ? Path.Combine(licences, licence + ".txt") : null;

        if (ownText != null)
        {
            labels.Add(texts.Add(File.ReadAllText(Path.Combine(licences, ownText)), "The licence of " + id));
        }
        else if (standardText != null && File.Exists(standardText))
        {
            labels.Add(texts.Add(File.ReadAllText(standardText), licence));
        }

        return labels.Distinct().ToList();
    }

    private static string CopyrightLine(string copyright) =>
        Regex.IsMatch(copyright, "^(copyright|©|\\(c\\))", RegexOptions.IgnoreCase) ? copyright : "Copyright: " + copyright;

    private string Compose(List<string> entries, TextRegistry texts)
    {
        var attribution = File.ReadAllText(Path.Combine(SharedDirectory, "contract", "attribution.json"));
        var bundled = Directory.GetFiles(Path.Combine(SharedDirectory, "third-party", "bundled"), "*.txt")
            .OrderBy(file => file, StringComparer.Ordinal)
            .Select(file => Normalise(File.ReadAllText(file)));

        var output = new StringBuilder();
        output.Append("THIRD-PARTY NOTICES\n\n");
        output.Append(JsonString(attribution, "project_name") + ", " + Implementation + " implementation\n");
        output.Append(JsonString(attribution, "notice") + "\n\n");
        output.Append(
            "This software includes the third-party components listed below, each under its own licence. Each entry\n"
            + "gives the component's version, its licence, its copyright notice and where its source code can be\n"
            + "obtained, and refers by number to the licence and notice texts that apply to it, which follow the list\n"
            + "in full. This file is generated when the software is built, from the packages the build restored.\n");

        foreach (var entry in bundled.Concat(entries))
        {
            output.Append("\n" + Rule + "\n" + entry + "\n");
        }

        output.Append("\n" + Rule.Replace('-', '=') + "\nLICENCE AND NOTICE TEXTS\n");
        foreach (var text in texts.All)
        {
            output.Append("\n" + Rule + "\n" + text + "\n");
        }

        return output.ToString();
    }

    private static string JsonString(string json, string key)
    {
        var match = Regex.Match(json, "\"" + key + "\"\\s*:\\s*\"((?:[^\"\\\\]|\\\\.)*)\"");
        return Regex.Unescape(match.Groups[1].Value);
    }

    internal static string Normalise(string text) =>
        string.Join("\n", text.Replace("\r\n", "\n").Split('\n').Select(line => line.TrimEnd())).Trim('\n', '﻿');

    private void WriteIfChanged(string content)
    {
        if (File.Exists(OutputFile) && File.ReadAllText(OutputFile) == content)
        {
            return;
        }

        Directory.CreateDirectory(Path.GetDirectoryName(OutputFile));

        // With a byte order mark, so a browser reads the file as UTF-8 whatever content type it is served with.
        File.WriteAllText(OutputFile, content, new UTF8Encoding(true));
    }

    /// <summary>Every distinct text, numbered in the order the notices first refer to it.</summary>
    private sealed class TextRegistry
    {
        private readonly Dictionary<string, string> labels = new Dictionary<string, string>();
        private readonly List<string> all = new List<string>();

        public IEnumerable<string> All => all;

        public string Add(string text, string title)
        {
            var body = Normalise(text);
            if (!labels.TryGetValue(body, out var label))
            {
                label = "[" + (labels.Count + 1) + "]";
                labels.Add(body, label);
                all.Add(label + " " + title + "\n\n" + body);
            }

            return label;
        }
    }

    private sealed class ComponentFacts
    {
        private readonly Dictionary<string, string> facts = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);

        public static ComponentFacts Read(string path)
        {
            var result = new ComponentFacts();
            foreach (var fields in File.ReadAllLines(path).Where(line => line.Length > 0 && line[0] != '#').Select(line => line.Split('\t')))
            {
                result.facts[fields[0] + "\t" + fields[1]] = fields[2];
            }

            return result;
        }

        public string Get(string component, string field) =>
            facts.TryGetValue(component + "\t" + field, out var value) ? value : null;
    }

    private sealed class Nuspec
    {
        public string LicenceExpression { get; private set; }

        public string LicenceFile { get; private set; }

        public string Copyright { get; private set; }

        public string Authors { get; private set; }

        public string Source { get; private set; }

        public static Nuspec Read(string path)
        {
            var metadata = XDocument.Load(path).Root.Elements().First(element => element.Name.LocalName == "metadata");
            string Value(string name) => Blank(metadata.Elements().FirstOrDefault(element => element.Name.LocalName == name)?.Value);
            var licence = metadata.Elements().FirstOrDefault(element => element.Name.LocalName == "license");
            var repository = metadata.Elements().FirstOrDefault(element => element.Name.LocalName == "repository");

            return new Nuspec
            {
                LicenceExpression = (string)licence?.Attribute("type") == "expression" ? Blank(licence.Value) : null,
                LicenceFile = (string)licence?.Attribute("type") == "file" ? Blank(licence.Value) : null,
                Copyright = Value("copyright"),
                Authors = Value("authors"),
                Source = Blank((string)repository?.Attribute("url")) ?? Value("projectUrl"),
            };
        }

        private static string Blank(string value) => string.IsNullOrWhiteSpace(value) ? null : value.Trim();
    }
}
