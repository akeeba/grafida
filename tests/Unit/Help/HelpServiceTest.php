<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Help;

use Grafida\Help\HelpService;
use Grafida\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers the two things the documentation pipeline can silently get wrong: the
 * manifest (which is the *only* index — nothing is discovered by scanning, so a
 * malformed entry has to fail visibly rather than take the table of contents
 * down with it) and the link rewriting that lets one set of Markdown files serve
 * both the GitHub wiki and a webview that can follow neither kind of link.
 */
final class HelpServiceTest extends TestCase
{
    private string $docsDir = '';

    protected function setUp(): void
    {
        $this->docsDir = sys_get_temp_dir() . '/grafida-help-test-' . uniqid('', true);
        mkdir($this->docsDir . '/images', 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->docsDir === '' || !is_dir($this->docsDir)) {
            return;
        }

        foreach (glob($this->docsDir . '/{,images/}*', \GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        @rmdir($this->docsDir . '/images');
        @rmdir($this->docsDir);
    }

    /** @param array<int, array<string, mixed>> $pages */
    private function writeManifest(array $pages, string $home = 'Home'): void
    {
        file_put_contents(
            $this->docsDir . '/_manifest.json',
            (string) json_encode(['home' => $home, 'pages' => $pages])
        );
    }

    private function writePage(string $slug, string $markdown): void
    {
        file_put_contents($this->docsDir . '/' . $slug . '.md', $markdown);
    }

    private function service(): HelpService
    {
        return new HelpService($this->docsDir);
    }

    public function testContentsReadsTheManifestInOrder(): void
    {
        $this->writeManifest([
            ['slug' => 'Home', 'title' => 'Introduction'],
            ['slug' => 'Second', 'title' => 'Second page'],
        ]);

        $contents = $this->service()->contents();

        $this->assertSame('Home', $contents['home']);
        $this->assertCount(2, $contents['tree']);
        $this->assertSame('Introduction', $contents['tree'][0]['title']);
        $this->assertSame([], $contents['tree'][0]['children']);
    }

    public function testContentsNestsChildrenUnderTheirParent(): void
    {
        $this->writeManifest([
            ['slug' => 'Home', 'title' => 'Introduction'],
            [
                'slug'     => 'Parent',
                'title'    => 'A parent page',
                'children' => [
                    ['slug' => 'Child', 'title' => 'A child page'],
                ],
            ],
        ]);

        $tree = $this->service()->contents()['tree'];

        $this->assertCount(2, $tree);
        $this->assertSame('Parent', $tree[1]['slug']);
        $this->assertCount(1, $tree[1]['children']);
        $this->assertSame('Child', $tree[1]['children'][0]['slug']);
    }

    /** A section with no page of its own — the reason `slug` is optional. */
    public function testAHeadingNodeHasNoSlugButKeepsItsChildren(): void
    {
        $this->writeManifest([
            ['slug' => 'Home', 'title' => 'Introduction'],
            [
                'title'    => 'Connecting to a site',
                'children' => [['slug' => 'Child', 'title' => 'A child page']],
            ],
        ]);

        $tree = $this->service()->contents()['tree'];

        $this->assertNull($tree[1]['slug']);
        $this->assertSame('Connecting to a site', $tree[1]['title']);
        $this->assertSame('Child', $tree[1]['children'][0]['slug']);
    }

    /** A nested page is a page: it must render, not merely appear in the tree. */
    public function testANestedPageIsReachable(): void
    {
        $this->writeManifest([
            ['title' => 'A section', 'children' => [
                ['title' => 'A subsection', 'children' => [
                    ['slug' => 'Deep', 'title' => 'A deep page'],
                ]],
            ]],
        ]);
        $this->writePage('Deep', "# Deep\n\nText.\n");

        $page = $this->service()->page('Deep');

        $this->assertNotNull($page);
        $this->assertSame('A deep page', $page['title']);
    }

    public function testNestingIsCappedSoARunawayManifestCannotProduceAnUnusableTree(): void
    {
        // Six levels; only the first four survive.
        $node = ['slug' => 'Level6', 'title' => 'Level 6'];
        for ($level = 5; $level >= 1; $level--) {
            $node = ['slug' => 'Level' . $level, 'title' => 'Level ' . $level, 'children' => [$node]];
        }

        $this->writeManifest([$node], 'Level1');
        $this->writePage('Level5', "# Level 5\n");

        $depth = 0;
        $cursor = $this->service()->contents()['tree'];
        while ($cursor !== []) {
            $depth++;
            $cursor = $cursor[0]['children'];
        }

        $this->assertSame(4, $depth);
        // Level 5 was cut, so it is not a page either, however real its file is.
        $this->assertNull($this->service()->page('Level5'));
    }

    public function testContentsSkipsAnEntryWhoseSlugCouldNotBeAFileName(): void
    {
        $this->writeManifest([
            ['slug' => '../etc/passwd', 'title' => 'Nope'],
            ['slug' => 'Home', 'title' => 'Introduction'],
        ]);

        $contents = $this->service()->contents();

        $this->assertCount(1, $contents['tree']);
        $this->assertSame('Home', $contents['tree'][0]['slug']);
    }

    /**
     * An unlinkable node takes its subtree with it: keeping the children would
     * leave them under a section with no way to reach it.
     */
    public function testAnUnusableSlugDropsItsChildrenToo(): void
    {
        $this->writeManifest([
            ['slug' => 'Home', 'title' => 'Introduction'],
            ['slug' => 'not a slug', 'title' => 'Nope', 'children' => [
                ['slug' => 'Orphan', 'title' => 'Orphan'],
            ]],
        ]);

        $contents = $this->service()->contents();

        $this->assertCount(1, $contents['tree']);
        $this->assertNull($this->service()->page('Orphan'));
    }

    public function testContentsFallsBackToTheFirstPageWhenHomeIsNotOneOfThem(): void
    {
        $this->writeManifest([['slug' => 'Second', 'title' => 'Second page']], 'Missing');

        $this->assertSame('Second', $this->service()->contents()['home']);
    }

    /** The fallback is depth-first: a manifest may perfectly well open with a heading. */
    public function testTheHomeFallbackLooksInsideAHeading(): void
    {
        $this->writeManifest([
            ['title' => 'A section', 'children' => [['slug' => 'Inner', 'title' => 'Inner page']]],
        ], 'Missing');

        $this->assertSame('Inner', $this->service()->contents()['home']);
    }

    public function testMissingManifestYieldsAnEmptyTableOfContents(): void
    {
        $contents = $this->service()->contents();

        $this->assertSame('', $contents['home']);
        $this->assertSame([], $contents['tree']);
    }

    public function testPageRendersMarkdownAndUsesTheManifestTitle(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "# Heading\n\nSome **bold** text.\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertSame('Introduction', $page['title']);
        $this->assertStringContainsString('<h1>Heading</h1>', $page['html']);
        $this->assertStringContainsString('<strong>bold</strong>', $page['html']);
    }

    public function testPageRendersGithubFlavouredExtras(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "| A | B |\n|---|---|\n| 1 | 2 |\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringContainsString('<table>', $page['html']);
    }

    /**
     * The tags GitHub neutralises must be neutralised here too, or the two
     * consumers stop agreeing about what a page means — and this HTML is written
     * into the SPA with innerHTML.
     */
    public function testDisallowedRawHtmlIsEscapedJustAsGithubEscapesIt(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "<script>alert(1)</script>\n\nBut <kbd>Ctrl</kbd> survives.\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringNotContainsString('<script>', $page['html']);
        $this->assertStringContainsString('<kbd>Ctrl</kbd>', $page['html']);
    }

    /**
     * GitHub's alert blockquotes are a GitHub *rendering* feature, not part of
     * the GFM spec, so CommonMark's GFM extension does not implement them and
     * `HelpService` synthesises them. Without that, the marker survives as a
     * literal `[!NOTE]` line — the one place the wiki and the app would visibly
     * disagree about what a page means.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function alertProvider(): array
    {
        return [
            'note'      => ['NOTE', 'help-alert-note', 'fa-circle-info', 'Note'],
            'tip'       => ['TIP', 'help-alert-tip', 'fa-lightbulb', 'Tip'],
            'important' => ['IMPORTANT', 'help-alert-important', 'fa-circle-exclamation', 'Important'],
            'warning'   => ['WARNING', 'help-alert-warning', 'fa-triangle-exclamation', 'Warning'],
            'caution'   => ['CAUTION', 'help-alert-caution', 'fa-ban', 'Caution'],
        ];
    }

    #[DataProvider('alertProvider')]
    public function testGithubAlertsBecomeStyledCallouts(
        string $marker,
        string $class,
        string $icon,
        string $label
    ): void {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "> [!{$marker}]\n> The body.\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringContainsString('<blockquote class="help-alert ' . $class . '">', $page['html']);
        $this->assertStringContainsString($icon, $page['html']);
        $this->assertStringContainsString('>' . ' ' . $label . '</p>', $page['html']);
        $this->assertStringContainsString('<p>The body.</p>', $page['html']);
        // The marker itself must be gone, not merely styled around.
        $this->assertStringNotContainsString('[!' . $marker . ']', $page['html']);
    }

    public function testAnAlertKeepsTheInlineMarkupInItsBody(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "> [!IMPORTANT]\n> See [the site](https://example.test) and **this**.\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringContainsString('data-help-external="1"', $page['html']);
        $this->assertStringContainsString('<strong>this</strong>', $page['html']);
    }

    /** GitHub renders an unknown marker as a plain quote too; agreeing costs nothing. */
    public function testAnUnknownAlertMarkerIsLeftAlone(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "> [!BOGUS]\n> Body.\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringContainsString('<blockquote>', $page['html']);
        $this->assertStringContainsString('[!BOGUS]', $page['html']);
        $this->assertStringNotContainsString('help-alert', $page['html']);
    }

    public function testAnOrdinaryBlockquoteIsUntouched(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "> Just a quotation.\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringContainsString('<blockquote>', $page['html']);
        $this->assertStringNotContainsString('help-alert', $page['html']);
    }

    /** A marker with no body would otherwise leave a stray empty paragraph. */
    public function testAnAlertWithNoBodyLeavesNoEmptyParagraph(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "> [!NOTE]\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringContainsString('help-alert-note', $page['html']);
        $this->assertStringNotContainsString('<p></p>', $page['html']);
    }

    public function testUnknownSlugIsNull(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "# Heading\n");

        $this->assertNull($this->service()->page('Elsewhere'));
    }

    /**
     * A page listed in the manifest whose file was never written must not render
     * as an empty page — the Help screen has to be able to say it is missing.
     */
    public function testManifestEntryWithNoFileIsNull(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);

        $this->assertNull($this->service()->page('Home'));
    }

    public function testRelativeLinksAreTaggedAsPageLinks(): void
    {
        $this->writeManifest([
            ['slug' => 'Home', 'title' => 'Introduction'],
            ['slug' => 'Other-Page', 'title' => 'Other'],
        ]);
        $this->writePage('Home', "See [the other page](Other-Page).\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringContainsString('data-help-page="Other-Page"', $page['html']);
        $this->assertStringNotContainsString('data-help-external', $page['html']);
    }

    /** A `.md` suffix a writer added out of habit must not become part of the slug. */
    public function testTheMarkdownSuffixAndFragmentAreStrippedFromAPageLink(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "See [there](Other-Page.md#a-section).\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringContainsString('data-help-page="Other-Page"', $page['html']);
    }

    public function testAbsoluteLinksAreTaggedAsExternal(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "See [the site](https://example.test/page).\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringContainsString('data-help-external="1"', $page['html']);
        $this->assertStringContainsString('href="https://example.test/page"', $page['html']);
        $this->assertStringNotContainsString('data-help-page', $page['html']);
    }

    /**
     * The fragment has to survive: `…/grafida#readme` is a link to a section of
     * the README, and dropping it would land the reader at the top of the page.
     */
    public function testAnExternalLinkKeepsItsFragment(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "See the [README](https://github.com/akeeba/grafida#readme).\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringContainsString('href="https://github.com/akeeba/grafida#readme"', $page['html']);
        $this->assertStringContainsString('data-help-external="1"', $page['html']);
    }

    /**
     * Support\UrlOpener accepts http(s) only, so tagging a `mailto:` would turn
     * a click into an error toast. It is left untagged; the SPA swallows the
     * click so it cannot navigate the webview away either.
     */
    public function testNonHttpSchemesAreNotTaggedAsExternal(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "Write to [us](mailto:nobody@example.test).\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringContainsString('href="mailto:nobody@example.test"', $page['html']);
        $this->assertStringNotContainsString('data-help-external', $page['html']);
        $this->assertStringNotContainsString('data-help-page', $page['html']);
    }

    /** The browser can scroll the pane by itself; tagging this would break that. */
    public function testInPageFragmentLinksAreLeftAlone(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "Jump to [the caveats](#caveats).\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringContainsString('href="#caveats"', $page['html']);
        $this->assertStringNotContainsString('data-help-page', $page['html']);
        $this->assertStringNotContainsString('data-help-external', $page['html']);
    }

    public function testImagesArePointedAtTheHelpImageRoute(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        $this->writePage('Home', "![A screenshot](images/screen.png)\n");

        $page = $this->service()->page('Home');

        $this->assertNotNull($page);
        $this->assertStringContainsString('src="/api/help/image/screen.png"', $page['html']);
    }

    public function testImageReadsFromTheImagesDirectory(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);
        file_put_contents($this->docsDir . '/images/screen.png', 'PNGBYTES');

        $image = $this->service()->image('screen.png');

        $this->assertNotNull($image);
        $this->assertSame('image/png', $image['mime']);
        $this->assertSame('PNGBYTES', $image['data']);
    }

    public function testImageRejectsAnythingThatIsNotAPlainFileName(): void
    {
        $this->writeManifest([['slug' => 'Home', 'title' => 'Introduction']]);

        $this->assertNull($this->service()->image('../_manifest.json'));
        $this->assertNull($this->service()->image('sub/dir.png'));
        $this->assertNull($this->service()->image('no-extension'));
    }

    public function testMissingImageIsNull(): void
    {
        $this->assertNull($this->service()->image('absent.png'));
    }
}
