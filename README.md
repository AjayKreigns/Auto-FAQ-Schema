=== Auto FAQ Schema ===
Contributors: ajaykumark
Tags: faq, schema, structured-data, gutenberg, seo, accordion, analytics
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a Gutenberg block for FAQs with automatic Schema.org FAQPage structured data, rich text answers, advanced global color customization, and an analytics dashboard.

== Description ==

Auto FAQ Schema provides a custom Gutenberg block that lets you easily add beautifully styled FAQ sections to your WordPress site. The plugin automatically generates Schema.org FAQPage JSON-LD structured data in the background, making your content eligible for rich snippets in Google search results.

Features:
* Custom Gutenberg block with drag-and-drop FAQ management
* Rich text answers (bold, italic, links, lists)
* Automatic Schema.org FAQPage JSON-LD generation
* Smooth CSS height animations for accordion opening/closing
* Three icon styles: Plus, Chevron, Arrow
* Global Color Customization Dashboard with Live Preview
* Six customizable color settings: Primary, Background, Highlight, Heading Text, Answer Text, and Question Text
* "Frequently Asked Questions" H2 heading automatically added to all blocks
* Option to open the first item by default
* Option to allow multiple items open simultaneously
* FAQ Analytics Dashboard (track clicks, popular questions, and recent activity)
* Accessible markup with ARIA attributes
* Clean, lightweight frontend code
* Mobile responsive
* Respects prefers-reduced-motion for accessibility

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/auto-faq-schema`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to 'FAQ Analytics -> Settings' to configure your global colors
4. Add the "FAQ Block" to any page or post

== Changelog ==

= 1.5.0 =
* Moved color customization from individual blocks to a centralized global settings dashboard
* Added new color settings: Heading Text Color, Answer Text Color, and Question Text Color
* Renamed Answer Link Color to Highlight Color (now controls the left-border glow, icons, and links)
* Removed individual block color panel for a consistent, site-wide design experience

= 1.4.0 =
* Added a global <h2>Frequently Asked Questions</h2> heading above the FAQ block
* Added a "Reset to Defaults" button in the global settings
* Improved security: added capability checks, input sanitization, output escaping, and hex validation

= 1.3.0 =
* Introduced Global Settings Interface under FAQ Analytics
* Implemented global color controls using the WordPress Options API
* Added a real-time Live Preview to the settings page
* Enforced text contrast dynamically

= 1.2.0 =
* Added Rich Text answers (bold, italic, links, lists)
* Added Color Pickers in block sidebar (Primary, Accent, Background)
* Added FAQ Analytics Dashboard
* Added click tracking for FAQ items

= 1.1.0 =
* Added smooth height-based CSS animations
* Added icon style options (Plus, Chevron, Arrow)
* Added "Open First Item by Default" option
* Added "Allow Multiple Open" option

= 1.0.0 =
* Initial release
