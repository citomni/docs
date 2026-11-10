# CitOmni Documentation Contract

This contract defines where CitOmni documentation lives, what authority each place has, and how documentation stays true. It applies to every repository in the CitOmni organization and to packages built on CitOmni that follow the [CitOmni Coding & Documentation Conventions](./CONVENTIONS.md). It is the system-level form of "The owner documents" in the [CitOmni Code Documentation Guide](./code-documentation-guide.md): Every fact has one home.

Documentation has readers who start anywhere: A PHP developer who has never seen CitOmni, a developer who knows it well, and an AI session that sees only what it is given. Every rule below serves two goals: Each reader can get from where they start to the authoritative source, and each statement is true when it is written and stays true without separate maintenance.

---

## Document metadata

* **Authority:** Binding
* **Audience:** Everyone who writes or reviews CitOmni documentation
* **Document type:** Standard

---

## 1. Layers

| Layer | Owns | Authority | Updated |
|---|---|---|---|
| PHPDoc and inline comments | The contract of one element, and the rationale for a line | Contract | In the same change as the element |
| Guide trees ([demo-app](https://github.com/citomni/demo-app), [demo-provider](https://github.com/citomni/demo-provider)) | How each kind of file is written, with tested reference code | Binding for new code | When a rule changes, in the same change as the reference code and its tests |
| Package README | What one package provides and how it is used: Installation, configuration, services, failure modes, troubleshooting | Authoritative for the package's use | In the same change as the behavior it describes |
| Package `docs/` | Long-form material about one package: Design, operation, decisions, test reports | Guidance | In the same change, or with its Verified entries set to not verified (section 6) |
| citomni/docs | Cross-package concepts, contracts and technical standards; the learning path; navigation | Binding for the contracts and standards it owns; guidance otherwise | A contract changes together with what verifies it; the rest when a verification pass or a drift signal calls for it |
| [citomni/.github](https://github.com/citomni/.github) | Organization governance: Licensing, security policy, the contribution process, the package catalog | Binding for governance | When the governance changes |

Facts inside a file keep the homes that "The owner documents" gives them: The meaning of a cfg key stays in the baseline or config file that defines it, and a route stays in its route file. A README explains how they are used; it does not redefine them.

Derived context, such as AI instructions, presets and cheat sheets, may summarize any layer. It names the owner, yields to it on conflict, and is never the only home of a rule.

## 2. Precedence

1. The source shows what happens today. A document that disagrees with the source is wrong, or the source has a bug. Report the conflict; never pick one silently.
2. Between documents, the owner of a fact wins. Every other statement of the fact is a summary and links to the owner.
3. For new code, the guide trees are binding. The Code Documentation Guide wins over the PHPDoc sections of the guide trees.

## 3. Where documentation goes

Ask in order. The first yes decides.

1. Is it the contract of one element? Its PHPDoc.
2. Is it a rule for writing one kind of file, such as a Controller, a Repository or a template? The guide tree file that mirrors that file.
3. Is it about one package? The package README; long-form material in the package's `docs/`.
4. Is it organization governance: Licensing, security policy, the contribution process or the package catalog? citomni/.github.
5. Is it a cross-package concept, contract or technical standard, or part of the learning path? citomni/docs.

A task that spans packages but is mostly one package's concern, such as authentication, gets an orientation paragraph and a link in citomni/docs, and its substance in the package.

## 4. What citomni/docs holds

citomni/docs is the entry point for understanding, using and developing CitOmni as one framework. It is authoritative for the contracts and standards it owns, and the map to everything else. It ties together the documentation that packages and guide trees own; it does not replace it.

| Path | Holds |
|---|---|
| `README.md` | What CitOmni is, reading paths by role and by task, and where things live |
| `get-started.md` | From an empty directory to a running app and through one complete feature, by way of the app skeleton and the demo app |
| `concepts/` | How CitOmni works and why: Architecture, runtime modes, and the main flows through the system |
| `reference/` | Cross-package contracts with stable rule IDs |
| `contribute/` | Technical standards for every repository: Conventions, code documentation, testing, this contract |
| `decisions/` | Cross-package architecture decision records |
| `research/`, `reports/` | Dated studies and measurements |

The number of documents follows the readers' needs. A new document needs a reader who needs it, and it must pass section 3.

## 5. Writing for citomni/docs

- Explain what changes more slowly than the code: Concepts, relations, choices, reasons and rules. Link to details that can change while the concept stays the same: Signatures, cfg keys, file names and per-layer rules.
- Repeat a concept when an explanation needs it to be coherent. Do not repeat a detail; link to it or excerpt it.
- An example that uses real framework API is an excerpt of tested code and names its repository, path and commit. A sketch uses invented names only, so that neither a person nor an AI can mistake it for real API.
- A concept or tutorial cites a rule by its ID or links to it. It does not restate the rule as a rule of its own.
- Write in English.

## 6. Metadata

Every document in citomni/docs and in a package's `docs/` that describes code starts with a metadata block:

* **Authority:** `Binding`, `Guidance` or `Snapshot`
* **Sources:** The repositories and paths, or classes, that the content depends on, including Registries, cfg baselines and tests
* **Verified:** One entry per repository in Sources: The `owner/repo@sha` the document was checked against and the date, or `Not verified since <date>`
* **Verified by:** The tests and checks that verify the document's rule IDs, if it has any

Authority replaces Status, and Sources replaces Key types. Other fields, such as Audience, may stay. A snapshot carries Authority and its date; Sources and Verified do not apply to it. A document without the block counts as not verified.

A pass checks a document against a repository by reading the listed paths of that repository and every part of the document that depends on them. An entry moves to a newer commit after a pass over the listed paths that changed in between; paths that did not change keep their verification. A change to a source never resets an entry by itself; it raises a drift signal (section 9). An edit that changes what the document says about behavior either repeats the pass for the repositories involved or sets their entries to `Not verified since <date>`. A document counts as verified when every entry names a commit.

## 7. Rule IDs and verification

- Each statement of a contract in `reference/` gets a stable ID, as B1-B22 and R1-R8 in [Cfg Access](../reference/cfg-access.md). Other binding documents may number their rules the same way. Principles and recommendations get no IDs.
- An ID is never reused or renumbered. A withdrawn ID stays listed as withdrawn.
- Each ID states how it is verified:
    - By a test, for observable behavior that a deterministic test can decide. The case in the owning package's suite is named after the ID.
    - By a static check, for structural rules, such as which classes a layer may reference. The check is named after the ID.
    - By review, for rules that no tool can decide. The rule states what the reviewer checks.
- A suite or check that verifies IDs lists them in its README.

## 8. Snapshots and decisions

- Documents in `research/`, `reports/` and `decisions/` carry their date. After publication they change only by a dated, marked correction of a factual error, or by one line that names what superseded them.
- Write a decision record when a decision had real alternatives, when its reason is not evident from the code, or when it rejects a solution that would otherwise seem obvious. Other decisions need no record.
- A decision record states the context, the decision, the rejected alternatives and the consequences. It records why, links to where the rule lives, and does not restate the rule.
- A decision record is placed by section 3: A decision about one package goes in that package's `docs/decisions/`, and a cross-package decision in `decisions/` of citomni/docs. It is delivered with the change that carries out the decision: In the same commit when both are in one repository, otherwise in a coordinated commit.
- A changed decision gets a new record that supersedes the old one.

## 9. Drift signals

Drift tooling reports; it never rewrites. It reports:

- dead links,
- identifiers that no longer exist, except names the document defines itself,
- paths in Sources that changed since their Verified commit,
- rule IDs whose stated test or check does not exist, and tests or checks that name an ID no document defines,
- excerpts that no longer match their source.

A signal becomes a finding only when someone has read the document at that spot. Until the tooling exists, a verification pass makes these checks by hand.

## 10. Paths

Paths in citomni/docs are API: AI instructions, presets and other repositories cite them. A document that is moved or removed while it has known inbound links leaves a stub that names its new home.

---

## Related documents

- [CitOmni Code Documentation Guide](./code-documentation-guide.md)
- [CitOmni Coding & Documentation Conventions](./CONVENTIONS.md)
- [CitOmni Testing Guide](./testing-guide.md)
- [Contributing to CitOmni](https://github.com/citomni/.github/blob/main/CONTRIBUTING.md)
