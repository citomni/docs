# CitOmni Code Documentation Guide

This guide defines how CitOmni code is documented in PHPDoc and inline comments. It applies to framework packages, providers and applications that follow the [CitOmni Coding & Documentation Conventions](https://github.com/citomni/.github/blob/main/CONVENTIONS.md).

Code documentation has two readers: Someone who changes the code, and someone who relies on it without reading the body. Either may be new to the code base, and either may be an AI session that sees nothing but the file. Every rule below serves one goal: Documentation that is true when it is written and stays true without separate maintenance.

## Standards

PHP-FIG's [PSR-5](https://github.com/php-fig/fig-standards/blob/master/proposed/phpdoc.md) (PHPDoc) and [PSR-19](https://github.com/php-fig/fig-standards/blob/master/proposed/phpdoc-tags.md) (PHPDoc tags) are drafts, not accepted standards. CitOmni follows their syntax for DocBlocks and tags.

Types use the PHPStan/Psalm syntax, because the PSR-5 type grammar has no array shapes (`array{id:int, title:string}`) and no `list<...>`.

Neither draft says what to document. This guide does.

## Principles

### Document what the element's own code makes true

Test every statement with one question: Can a diff that does not touch this element make the statement false? If it can, do one of three things:

- **Remove it** when it repeats something derivable from code or owned elsewhere, such as routes, cfg keys, collaborators or callers.
- **Rewrite it as a requirement.** "Route it only where every client may see drafts" stays true whatever the route files say. A route that breaks it is the bug.
- **Point to the owner** with `@see` instead of copying the owner's behavior.

A stable contract may be referenced; another element's current state may not. A framework base class's documented hook and a callee's documented exception are contracts. A route path, a cfg value, a template, or the reasons another class currently returns are state.

### The owner documents

Every fact has one home:

- Routes live in route files.
- The meaning of a cfg key lives in the file or Registry baseline that defines it.
- Framework behavior lives in the framework's PHPDoc.
- Patterns and teaching live in READMEs and in these docs.

When a fact is missing at its home, add it there instead of repeating it in every caller.

### Contract in PHPDoc, rationale inline

PHPDoc states what a caller can rely on. An inline comment explains why a specific line looks the way it does.

Test: A refactor that keeps the behavior must not require a PHPDoc change. If it does, the PHPDoc described implementation.

### Plain text

Write PHPDoc as plain text. Backticks are not required.

Tools may render descriptions as Markdown, so avoid paired `*` and text that looks like HTML, such as `<select multiple>`, which a renderer swallows. Typography follows the conventions: ASCII punctuation and `->` for arrows.

## Class PHPDoc

A class PHPDoc tells a developer what to know before adding or changing a member.

```php
/**
 * <ClassName>: <Role: what it is and which layer, one line.>
 *
 * <Purpose and scope: why it exists and what it deliberately leaves to others. Prose.>
 *
 * Rules:
 * - <Requirement every present and future member must meet.>
 *
 * Typical usage: <only when correct use spans several members in a required order>
 *   <short example>
 */
```

- **Summary:** The summary line is the role. There is no separate Role section.
- **Description:** Purpose and scope. Why the class exists, and what it deliberately leaves to other layers. It names no individual members.
- **Rules:** Requirements for every present and future member, written as requirements. A member that breaks a rule has a bug; the rule does not go stale.
- **Typical usage:** Only when correct use spans several members in a required order, such as a `configure()` that must precede use, or a begin/commit pair. Instantiation (`new X($this->app)`) belongs to the base class and the layer conventions. A single method call belongs to that method's PHPDoc.

Test for a class-level statement: Would a member added tomorrow be covered by it? If the statement is only true because of what the current members happen to do, it belongs on those members.

Leave out:

- Summaries of member behavior, such as "every action except X does Y".
- Lists of collaborators, members, routes or cfg keys.
- Facts readable from the declaration just below, such as `final` or the base class.

Example, from [citomni/demo-app](https://github.com/citomni/demo-app/blob/main/src/Http/Controller/ArticleController.php):

```php
/**
 * ArticleController: HTTP adapter for articles.
 *
 * Gives clients and editors HTTP access to articles. It decides how a result is answered,
 * never whether an action may happen; domain decisions belong to Policies and Operations.
 *
 * Rules:
 * - Read through ArticleRepository; change state only through Operations.
 * - Never let a draft reach a client that may not see drafts. Answer as for a missing article.
 */
```

## Method PHPDoc

A method PHPDoc tells a caller what it can rely on beyond the signature.

```php
/**
 * <One-line summary, imperative.>
 *
 * <Purpose and context. Prose. Mandatory when the method changes state, has more than two
 * outcomes or coordinates several collaborators; optional otherwise.>
 *
 * Behavior: <optional when the summary and tags already say everything>
 * - <Outcome or guarantee the caller can rely on>
 *     - <Detail or alternative; 1), 2) only when order matters>
 * - <Precedence/ordering rules>
 * - <Side effects>
 *
 * Notes: <only for caveats, performance, determinism or environment assumptions not evident from the method itself>
 * - <Caveat>
 *
 * Typical usage: <only for public methods that code calls directly; never on dispatch targets>
 *   <Short code example>
 *
 * @param  type  $name  <Description, only when it adds to the type.>
 * @return type  <Description, only when it adds to the type.>
 * @throws \Fully\Qualified\Exception  <When and why; one per contractual exception that can escape.>
 * @see \Fully\Qualified\Element::member()  <Why to look there; only instead of copying its behavior.>
 */
```

### Sections

- **Summary:** Imperative, one line, no formatting. No routes or other facts owned elsewhere.
- **Description:** Purpose and context in prose. It does not repeat Behavior. Mandatory when the method does at least one of the following; optional otherwise:
    - changes state,
    - has more than two outcomes,
    - coordinates several domain collaborators. Repositories, Policies, Operations and Services with domain logic count. Transport services such as `response`, `tplEngine` or `flash` do not.
- **Behavior:** Observable outcomes and guarantees: Status codes and shapes, side effects, state transitions, ordering and concurrency. Not the steps the body takes. Optional when the summary and tags already say everything.
- **Notes:** Caveats, performance, determinism and environment assumptions that are not evident from the method itself.
- **Typical usage:** Only for public methods that code calls directly. Never on dispatch targets: Controller actions, Command `execute()` and job handlers are called by the Router, the Runner or the JobRunner.

A method PHPDoc does not repeat the class's Rules.

### Tags

- **`@param`** for every parameter. Describe only what the type does not say: Range, format, unit or shape.
- **`@return`** always, including `void` and `never`. Describe only what the type does not say. Give arrays a shape.
- **`@throws`** for every exception that is part of the contract and can escape:
    - precondition violations,
    - documented failures,
    - exceptions from callees that the method does not guard and that stem from its own input or cfg reads.

  Infrastructure failures that the global error handler owns, such as `\JsonException` from encoding or database errors, are not listed.
- **`@see`** with an FQSEN when the PHPDoc would otherwise copy another element's behavior. Prefer it over prose that names another file. An `@see` goes stale on a rename like any other reference, so update it with the rename.
- **Order:** `@param`, `@return`, `@throws`, `@see`. Extra spaces for alignment are allowed.

### Examples

A small method needs only the summary and its tags:

```php
	/**
	 * Render the newest published articles.
	 *
	 * @return void
	 */
```

A method whose contract depends on a callee points to it instead of copying it:

```php
	/**
	 * Render one published article with its reading time.
	 *
	 * Behavior:
	 * - 404 through httpError() when no published article has the id.
	 *
	 * @param  string  $id  Article id as captured by the route.
	 * @return void
	 * @throws \InvalidArgumentException  When Words::readingMinutes() rejects articles.words_per_minute.
	 * @see \App\Util\Words::readingMinutes()  How reading_minutes is computed.
	 */
```

A method that changes state and has several outcomes gets a description and a full Behavior section:

```php
	/**
	 * Publish one draft and report the outcome through a flash message and a redirect.
	 *
	 * The decision belongs to PublishArticle. This action validates the request and translates
	 * the Operation's status for the browser, using Post/Redirect/Get so that a reload does not
	 * post again.
	 *
	 * Behavior:
	 * - 404 through httpError() when the id is below 1.
	 * - Every other path ends in a 303 redirect to /drafts.html with exactly one flash message:
	 *     - Error when the CSRF token is rejected. Nothing is published.
	 *     - Success when the draft was published.
	 *     - Error when the article does not exist.
	 *     - Warning when PublishArticle refuses. The message does not name the reasons.
	 *
	 * Notes:
	 * - Publishes without access control. Route it only where every client may publish.
	 *
	 * @param  string  $id  Article id as captured by the route.
	 * @return void
	 * @see \App\Operation\PublishArticle::execute()  The statuses behind the flash messages.
	 */
```

## Constants and properties

A single-line PHPDoc states what the value means, not where it is used:

```php
	/** Maximum number of articles in each list. */
	private const LIST_LIMIT = 20;
```

A list of users goes stale with the next new user. A search finds them.

## Lists in PHPDoc

- A label (`Behavior:`, `Notes:`, `Rules:`) is followed directly by its `- ` items.
- Nested items are indented 4 spaces. Use at most two levels.
- `-` marks details, alternatives and sets. `1)`, `2)` mark items only when their order matters, such as steps or precedence. A numbered list reads as a sequence, even when it is not meant as one.
- Indent with spaces inside DocBlock text, not tabs.

## Inline comments

- Explain why at the line: A guard, an ordering rule, a deliberate deviation, or a security or performance choice.
- A comment may state an assumption the code relies on. It must not describe another file's current state. When the assumption breaks, the code is wrong, and the comment points at the bug:

  ```php
  // RESULT_REFUSED is the only status left; a new status needs its own branch.
  ```

- Do not repeat what the next line says.

Section dividers are optional and only for code long enough that visual separation helps. Between groups of methods, use a class-level divider indented one tab:

```php
	// ----------------------------------------------------------------
	// Section label
	// ----------------------------------------------------------------
```

Inside a method with distinct phases, use a numbered single-line divider indented to the surrounding code:

```php
		// -- 1. Validate input -----------------------------------------
```

Place an explanation, when needed, as a normal comment directly below the divider.

## Review checklist

- [ ] No statement can be made false by a diff that does not touch its element.
- [ ] The class PHPDoc states role, purpose and scope, and rules. It has no member summaries or lists.
- [ ] Every method PHPDoc has a summary, a description where it is mandatory, and Behavior as outcomes rather than steps.
- [ ] Every method has `@param` and `@return`; `@throws` covers contractual exceptions; `@see` replaces copied behavior.
- [ ] Rationale sits inline at the line it explains, and assumptions are stated as assumptions.
- [ ] Nested lists use 4 spaces, and numbers mark order only.
- [ ] Text is plain ASCII.

## Related documents

- [CitOmni Coding & Documentation Conventions](https://github.com/citomni/.github/blob/main/CONVENTIONS.md)
- [CONVENTIONS (entry point)](./CONVENTIONS.md)
- [CitOmni Testing Guide](./testing-guide.md)
- [PSR-5: PHPDoc (draft)](https://github.com/php-fig/fig-standards/blob/master/proposed/phpdoc.md)
- [PSR-19: PHPDoc tags (draft)](https://github.com/php-fig/fig-standards/blob/master/proposed/phpdoc-tags.md)
