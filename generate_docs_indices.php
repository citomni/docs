<?php
declare(strict_types=1);

/**
 * generate_docs_indices.php
 *
 * Generates/refreshes:
 * 1) Root README.md (WIP + auto index grouped by top-level).
 * 2) Per-directory README.md auto-index blocks (files + subfolders),
 *    updating only the section between <!-- AUTO-INDEX:BEGIN --> ... <!-- AUTO-INDEX:END -->.
 *
 * Behavior:
 * - Scans recursively for *.md files.
 * - Skips root README.md as a "content" page.
 * - Excludes /.git/, /site/, /assets/ from indices (still allows README in parent dirs).
 * - Creates README.md where missing, with a minimal header + auto-index block.
 *
 * PHP 8.2+, tabs, K&R braces. No external deps.
 */

const EXCLUDE_DIRS = ['/.git/', '/site/', '/assets/'];
const SUPPORT_EMAIL = 'support@citomni.com';
const AUTO_BEGIN = '<!-- AUTO-INDEX:BEGIN -->';
const AUTO_END   = '<!-- AUTO-INDEX:END -->';

function normPath(string $path): string {
	$path = str_replace('\\', '/', $path);
	$path = preg_replace('#/+#', '/', $path) ?? $path;
	if (\strlen($path) > 3) {
		$path = rtrim($path, '/');
	}
	return $path;
}

function relFromRoot(string $absPath, string $root): string {
	$abs = normPath($absPath);
	$root = rtrim(normPath($root), '/');
	return str_starts_with($abs, $root . '/') ? substr($abs, \strlen($root) + 1) : $abs;
}

function isExcludedPath(string $absPath): bool {
	$abs = normPath($absPath);
	foreach (EXCLUDE_DIRS as $needle) {
		if (str_contains($abs, $needle)) {
			return true;
		}
	}
	return false;
}

function listDirs(string $dir): array {
	$out = [];
	foreach (scandir($dir) ?: [] as $name) {
		if ($name === '.' || $name === '..') {
			continue;
		}
		$path = $dir . '/' . $name;
		if (is_dir($path)) {
			$out[] = $path;
		}
	}
	natcasesort($out);
	return array_values($out);
}

function listMarkdownFiles(string $dir): array {
	$out = [];
	foreach (scandir($dir) ?: [] as $name) {
		if ($name === '.' || $name === '..') {
			continue;
		}
		$path = $dir . '/' . $name;
		if (is_file($path) && strcasecmp(pathinfo($path, PATHINFO_EXTENSION), 'md') === 0) {
			$out[] = $path;
		}
	}
	natcasesort($out);
	return array_values($out);
}

function humanTitle(string $pathRelative): string {
	// Use filename without extension; replace dashes/underscores
	$base = basename($pathRelative);
	$base = preg_replace('/\.md$/i', '', $base) ?? $base;
	$base = str_replace(['-', '_'], ' ', $base);
	$base = preg_replace('/\s+/', ' ', $base) ?? $base;
	return ucfirst(trim($base));
}

function topLevelOf(string $relativePath): string {
	$relativePath = ltrim($relativePath, '/');
	$pos = strpos($relativePath, '/');
	return $pos === false ? '(root)' : substr($relativePath, 0, $pos);
}

function replaceAutoIndexBlock(string $existing, string $newBlock): string {
	$begin = preg_quote(AUTO_BEGIN, '/');
	$end = preg_quote(AUTO_END, '/');
	$pattern = "/{$begin}.*?{$end}/s";
	if (preg_match($pattern, $existing)) {
		return preg_replace($pattern, $newBlock, $existing) ?? $existing;
	}
	// No block found: append at end with spacing.
	return rtrim($existing) . "\n\n" . $newBlock . "\n";
}

function directoryHasContentToIndex(string $absDir, string $root): bool {
	// There is indexable content if there is any *.md other than README.md or any subdir (non-excluded) with md files.
	$md = listMarkdownFiles($absDir);
	foreach ($md as $file) {
		$rel = relFromRoot($file, $root);
		if (strcasecmp(basename($file), 'README.md') !== 0 && !isExcludedPath($file)) {
			return true;
		}
	}
	foreach (listDirs($absDir) as $sub) {
		if (isExcludedPath($sub)) {
			continue;
		}
		// recursive peek
		$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sub, FilesystemIterator::SKIP_DOTS));
		foreach ($rii as $f) {
			/** @var SplFileInfo $f */
			if ($f->isFile() && strcasecmp($f->getExtension(), 'md') === 0) {
				if (strcasecmp($f->getFilename(), 'README.md') !== 0 && !isExcludedPath($f->getPathname())) {
					return true;
				}
			}
		}
	}
	return false;
}

/**
 * Build the auto-index block for a given directory (files + subfolders).
 */
function buildAutoIndexBlock(string $absDir, string $root): string {
	$relDir = relFromRoot($absDir, $root);
	$relPrefix = $relDir === '' ? '' : $relDir . '/';

	$lines = [];
	$lines[] = AUTO_BEGIN;
	$lines[] = '';
	$lines[] = '## Index';
	$lines[] = '';

	// Files in this dir (exclude README.md)
	foreach (listMarkdownFiles($absDir) as $file) {
		if (strcasecmp(basename($file), 'README.md') === 0) {
			continue;
		}
		if (isExcludedPath($file)) {
			continue;
		}
		$rel = relFromRoot($file, $root);
		$lines[] = '- [' . humanTitle($rel) . '](./' . basename($rel) . ')';
	}

	// Subfolders with at least one indexable md
	$subDirs = listDirs($absDir);
	foreach ($subDirs as $sub) {
		if (isExcludedPath($sub)) {
			continue;
		}
		if (!directoryHasContentToIndex($sub, $root)) {
			continue;
		}
		$subRel = relFromRoot($sub, $root);
		$lines[] = '- **' . basename($subRel) . '/** → [' . 'README' . '](./' . basename($subRel) . '/README.md)';
	}

	$lines[] = '';
	$lines[] = AUTO_END;
	return implode("\n", $lines);
}

/**
 * Ensure a directory README exists; if not, create a minimal header + auto block.
 * If exists, update only the auto block.
 */
function ensureDirectoryReadme(string $absDir, string $root): void {
	$readme = rtrim($absDir, '/') . '/README.md';
	$auto = buildAutoIndexBlock($absDir, $root);

	if (!file_exists($readme)) {
		$title = basename($absDir);
		$hdr = '# ' . $title . "\n\n" . "_Section landing page._\n\n" . '- [Back to Docs Home](../README.md)' . "\n\n";
		file_put_contents($readme, $hdr . $auto . "\n");
		return;
	}

	$existing = file_get_contents($readme) ?: '';
	$updated = replaceAutoIndexBlock($existing, $auto);
	if ($updated !== $existing) {
		file_put_contents($readme, $updated);
	}
}

/**
 * Generate the root README with grouped index.
 */
function generateRootReadme(string $root): void {
	$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

	$groups = []; // top-level => list of rel paths
	$count = 0;
	foreach ($rii as $f) {
		/** @var SplFileInfo $f */
		if (!$f->isFile() || strcasecmp($f->getExtension(), 'md') !== 0) {
			continue;
		}
		$abs = normPath($f->getPathname());
		$rel = relFromRoot($abs, $root);

		if (strcasecmp($rel, 'README.md') === 0 || strcasecmp($rel, 'CONVENTIONS.md') === 0) {
			continue;
		}
		if (isExcludedPath($abs)) {
			continue;
		}

		$top = topLevelOf($rel);
		$groups[$top] ??= [];
		$groups[$top][] = $rel;
		$count++;
	}

	/**
	 * Move the section's own README.md to the first position (if present).
	 * Example: "concepts/README.md" goes first under group "concepts".
	 */
	function promoteSectionReadme(string $group, array $list): array {
		// Root group never shows root README.md (already handled elsewhere).
		if ($group === '(root)') {
			return array_values($list);
		}

		$want = $group . '/README.md';
		$head = [];
		$tail = [];

		foreach ($list as $rel) {
			if (strcasecmp($rel, $want) === 0) {
				$head[] = $rel;
			} else {
				$tail[] = $rel;
			}
		}
		return array_values(array_merge($head, $tail));
	}

	// Sort
	uksort($groups, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
	foreach ($groups as $g => &$list) {
		natcasesort($list);
		$list = promoteSectionReadme($g, array_values($list));
	}
	unset($list);
	

	$now = date('Y-m-d H:i:s');
	$lines = [];
	$lines[] = '# CitOmni Documentation (Work in Progress)';
	$lines[] = '';
	$lines[] = 'This repository hosts the public documentation for the CitOmni Framework.';
	$lines[] = '';
	$lines[] = '> **Heads up:** The documentation is a work in progress. If you\'re missing anything specific, please reach out at **' . SUPPORT_EMAIL . '**.';
	$lines[] = '';
	$lines[] = '## Available docs (auto-generated)';
	$lines[] = '';

	if ($count === 0) {
		$lines[] = '*(No individual pages detected yet. Check back soon.)*';
		$lines[] = '';
	} else {
		foreach ($groups as $group => $list) {
			$lines[] = '### ' . $group;
			foreach ($list as $rel) {
				$lines[] = '- [' . $rel . '](./' . $rel . ')';
			}
			$lines[] = '';
		}
	}

	// Expected structure (same as earlier)
	$lines[] = '## Expected structure (reference)';
	$lines[] = '';
	$lines[] = 'Below is the planned layout. Some sections may be empty while we migrate content.';
	$lines[] = '';
	$lines[] = '```';
	$lines[] = '/README.md';
	$lines[] = '/get-started/';
	$lines[] = '  quickstart-http.md';
	$lines[] = '  quickstart-cli.md';
	$lines[] = '  skeletons.md';
	$lines[] = '/concepts/';
	$lines[] = '  runtime-modes.md';
	$lines[] = '  config-merge.md          # deterministic "last-wins"';
	$lines[] = '  services-and-providers.md';
	$lines[] = '  caching-and-warmup.md';
	$lines[] = '  error-handling.md';
	$lines[] = '/how-to/';
	$lines[] = '  build-a-provider.md';
	$lines[] = '  routes-and-controllers.md';
	$lines[] = '  csrf-cookies-sessions.md';
	$lines[] = '  perf-budgets-and-metrics.md  # p95 TTFB, RSS, req/watt-notes';
	$lines[] = '  deployment-one.com.md';
	$lines[] = '/troubleshooting/';
	$lines[] = '  providers-autoload.md';
	$lines[] = '  routes-404.md';
	$lines[] = '  csrf-token-mismatch.md';
	$lines[] = '  caching-warmup.md';
	$lines[] = '  composer-autoload.md';
	$lines[] = '  http-500-during-boot.md';
	$lines[] = '/policies/';
	$lines[] = '  security.md';
	$lines[] = '  public-uploads.md';
	$lines[] = '  cache-and-retention.md';
	$lines[] = '  backups.md';
	$lines[] = '  data-protection.md';
	$lines[] = '  error-pages.md';
	$lines[] = '  maintenance.md';
	$lines[] = '/reference/';
	$lines[] = '  config-keys.md           # complete key list with examples';
	$lines[] = '  cli-commands.md';
	$lines[] = '  http-objects.md          # Request/Response/Cookie';
	$lines[] = '  router-reference.md';
	$lines[] = '/packages/';
	$lines[] = '  kernel.md';
	$lines[] = '  http.md';
	$lines[] = '  cli.md';
	$lines[] = '  auth.md';
	$lines[] = '  testing.md';
	$lines[] = '/cookbook/';
	$lines[] = '  cms-starter.md';
	$lines[] = '  commerce-notes.md';
	$lines[] = '  admin-starter.md';
	$lines[] = '/contribute/';
	$lines[] = '  conventions.md           # coding/documentation conventions';
	$lines[] = '  docs-style-guide.md      # tone, examples, code blocks';
	$lines[] = '  issue-templates.md';
	$lines[] = '/legal/';
	$lines[] = '  licenses.md              # MIT/GPL explanations, SPDX';
	$lines[] = '  trademark.md             # TM usage, "official", "certified"';
	$lines[] = '  notice-template.md';
	$lines[] = '/programs/';
	$lines[] = '  certification.md         # criteria, green KPIs';
	$lines[] = '  partners.md              # partner program guidelines';
	$lines[] = '/release-notes/';
	$lines[] = '  index.md                 # links to package CHANGELOGs';
	$lines[] = '/assets/';
	$lines[] = '  img/ css/ js/';
	$lines[] = '```';
	$lines[] = '';
	$lines[] = '---';
	$lines[] = '_This README was generated automatically._  ';
	$lines[] = '_Last updated: ' . $now . '_';
	$lines[] = '';

	file_put_contents($root . '/README.md', implode("\n", $lines));
}

function main(): void {
	$root = normPath(getcwd() ?: '.');

	// 1) Generate/refresh root README
	generateRootReadme($root);

	// 2) Generate/refresh per-directory README auto blocks
	$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	$seenDirs = [];
	foreach ($rii as $f) {
		/** @var SplFileInfo $f */
		$dir = $f->getPath();
		$dir = normPath($dir);

		// Process each directory once
		if (isset($seenDirs[$dir])) {
			continue;
		}
		$seenDirs[$dir] = true;

		// Exclude certain directories entirely
		if (isExcludedPath($dir)) {
			continue;
		}

		// Ensure README & auto-index block for this directory
		ensureDirectoryReadme($dir, $root);
	}

	echo "Done. Root README and per-directory indices updated.\n";
}

main();
