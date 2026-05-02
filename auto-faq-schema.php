<?php
/**
 * Plugin Name: Auto FAQ Schema
 * Description: Adds a Gutenberg block for FAQs with automatic Schema.org FAQPage structured data, rich text answers, color customization, and analytics.
 * Version: 1.5.0
 * Author: Ajaykumar K
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: auto-faq-schema
 */

if (!defined('ABSPATH')) {
    exit;
}

class Auto_FAQ_Schema {

    private static $instance = null;
    private $faqs = array();
    private $block_count = 0;
    private $schema_output = false;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_block'));
        add_action('wp_footer', array($this, 'output_schema'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_auto_faq_track_click', array($this, 'track_faq_click'));
        add_action('wp_ajax_nopriv_auto_faq_track_click', array($this, 'track_faq_click'));
        add_action('admin_init', array($this, 'maybe_create_tables'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_auto_faq_reset_colors', array($this, 'reset_colors'));
        add_action('enqueue_block_editor_assets', array($this, 'hide_block_colors_panel'));
    }

    /**
     * Inject JS to hide the Colors PanelBody from the block editor sidebar.
     * Colors are managed globally via FAQ Analytics → Settings.
     */
    public function hide_block_colors_panel() {
        $script = '
        wp.domReady(function() {
            function hideColorsPanel() {
                var toggles = document.querySelectorAll(".components-panel__body-toggle");
                for (var i = 0; i < toggles.length; i++) {
                    if (toggles[i].textContent.trim() === "Colors") {
                        var panel = toggles[i].closest(".components-panel__body");
                        if (panel) { panel.style.display = "none"; }
                    }
                }
            }
            setTimeout(hideColorsPanel, 600);
            setTimeout(hideColorsPanel, 1800);
            document.addEventListener("click", function() { setTimeout(hideColorsPanel, 300); });
        });
        ';
        wp_add_inline_script('wp-blocks', $script);
    }

    public function register_settings() {
        register_setting('auto_faq_color_settings', 'auto_faq_primary_color', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#5c0931',
        ));
        register_setting('auto_faq_color_settings', 'auto_faq_accent_color', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#ffd700',
        ));
        register_setting('auto_faq_color_settings', 'auto_faq_bg_color', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#faf5f7',
        ));
        register_setting('auto_faq_color_settings', 'auto_faq_link_color', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#0ea5e9',
        ));
        register_setting('auto_faq_color_settings', 'auto_faq_heading_color', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#5c0931',
        ));
        register_setting('auto_faq_color_settings', 'auto_faq_answer_text_color', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#1f2937',
        ));
        register_setting('auto_faq_color_settings', 'auto_faq_question_text_color', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#5c0931',
        ));
    }

    /**
     * Security: nonce-protected, capability-checked reset handler.
     */
    public function reset_colors() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'auto-faq-schema'));
        }
        check_admin_referer('auto_faq_reset_colors_nonce');
        update_option('auto_faq_primary_color', '#5c0931');
        update_option('auto_faq_accent_color',  '#ffd700');
        update_option('auto_faq_bg_color',      '#faf5f7');
        update_option('auto_faq_link_color',            '#0ea5e9');
        update_option('auto_faq_heading_color',          '#5c0931');
        update_option('auto_faq_answer_text_color',      '#1f2937');
        update_option('auto_faq_question_text_color',    '#5c0931');
        wp_safe_redirect(add_query_arg(
            array('page' => 'auto-faq-settings', 'colors-reset' => '1'),
            admin_url('admin.php')
        ));
        exit;
    }

    public function maybe_create_tables() {
        $db_version = get_option('auto_faq_db_version', '0');
        if ($db_version === '1.0') {
            return; // table already up to date
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'auto_faq_analytics';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            faq_question text NOT NULL,
            page_url varchar(500) NOT NULL,
            page_title varchar(300) NOT NULL,
            click_count bigint(20) DEFAULT 1,
            last_clicked datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY faq_question (faq_question(100)),
            KEY page_url (page_url(100))
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        update_option('auto_faq_db_version', '1.0');
    }

    public function add_admin_menu() {
        add_menu_page(
            'FAQ Analytics',
            'FAQ Analytics',
            'manage_options',
            'auto-faq-analytics',
            array($this, 'render_analytics_page'),
            'dashicons-chart-bar',
            30
        );

        add_submenu_page(
            'auto-faq-analytics',
            'FAQ Settings',
            'Settings',
            'manage_options',
            'auto-faq-settings',
            array($this, 'render_settings_page')
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $primary_color      = get_option('auto_faq_primary_color',      '#5c0931');
        $bg_color           = get_option('auto_faq_bg_color',            '#faf5f7');
        $link_color         = get_option('auto_faq_link_color',          '#0ea5e9');
        $heading_color      = get_option('auto_faq_heading_color',       '#5c0931');
        $answer_text_color  = get_option('auto_faq_answer_text_color',   '#1f2937');
        $question_text_color = get_option('auto_faq_question_text_color','#5c0931');
        ?>
        <div class="wrap">
            <h1 style="color:#5c0931;">&#127859; FAQ Global Color Settings</h1>
            <p style="color:#6b7280; max-width:600px;">
                These colors serve as the <strong>global default</strong> for all FAQ blocks on your site.
                Individual blocks can still override these colors from the block editor's color panel.
            </p>

            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']) : ?>
                <div id="setting-error-settings_updated" class="notice notice-success settings-error is-dismissible">
                    <p><strong>Settings saved.</strong> Your global FAQ colors have been updated.</p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['colors-reset']) && '1' === $_GET['colors-reset']) : ?>
                <div class="notice notice-info is-dismissible">
                    <p><strong>Colors reset.</strong> All FAQ colors have been restored to defaults.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('auto_faq_color_settings'); ?>

                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:24px; max-width:900px; margin-top:24px;">

                    <!-- Primary Color -->
                    <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.08); padding:24px;">
                        <h3 style="margin-top:0; color:#5c0931; display:flex; align-items:center; gap:8px;">
                            <span style="display:inline-block; width:18px; height:18px; border-radius:50%; background:<?php echo esc_attr($primary_color); ?>; border:2px solid #e5e7eb;"></span>
                            Primary Color
                        </h3>
                        <p style="color:#6b7280; font-size:0.9em; margin-top:0;">Used for question text, left border accent, and answer links.</p>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <input type="color"
                                   id="auto_faq_primary_color"
                                   name="auto_faq_primary_color"
                                   value="<?php echo esc_attr($primary_color); ?>"
                                   style="width:56px; height:40px; border:none; border-radius:8px; cursor:pointer; padding:2px;">
                            <input type="text"
                                   id="auto_faq_primary_color_hex"
                                   value="<?php echo esc_attr($primary_color); ?>"
                                   maxlength="7"
                                   style="width:100px; padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-family:monospace;"
                                   oninput="document.getElementById('auto_faq_primary_color').value=this.value">
                        </div>
                        <script>document.getElementById('auto_faq_primary_color').addEventListener('input',function(){document.getElementById('auto_faq_primary_color_hex').value=this.value;});</script>
                    </div>


                    <!-- Background Color -->
                    <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.08); padding:24px;">
                        <h3 style="margin-top:0; color:#5c0931; display:flex; align-items:center; gap:8px;">
                            <span style="display:inline-block; width:18px; height:18px; border-radius:50%; background:<?php echo esc_attr($bg_color); ?>; border:2px solid #e5e7eb;"></span>
                            Background Color
                        </h3>
                        <p style="color:#6b7280; font-size:0.9em; margin-top:0;">Base background tone of the question rows and FAQ container.</p>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <input type="color"
                                   id="auto_faq_bg_color"
                                   name="auto_faq_bg_color"
                                   value="<?php echo esc_attr($bg_color); ?>"
                                   style="width:56px; height:40px; border:none; border-radius:8px; cursor:pointer; padding:2px;">
                            <input type="text"
                                   id="auto_faq_bg_color_hex"
                                   value="<?php echo esc_attr($bg_color); ?>"
                                   maxlength="7"
                                   style="width:100px; padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-family:monospace;"
                                   oninput="document.getElementById('auto_faq_bg_color').value=this.value">
                        </div>
                        <script>document.getElementById('auto_faq_bg_color').addEventListener('input',function(){document.getElementById('auto_faq_bg_color_hex').value=this.value;});</script>
                    </div>

                    <!-- Highlight Color -->
                    <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.08); padding:24px;">
                        <h3 style="margin-top:0; color:#5c0931; display:flex; align-items:center; gap:8px;">
                            <span style="display:inline-block; width:18px; height:18px; border-radius:50%; background:<?php echo esc_attr($link_color); ?>; border:2px solid #e5e7eb;"></span>
                            Highlight Color
                        </h3>
                        <p style="color:#6b7280; font-size:0.9em; margin-top:0;">Controls the left-border glow, expand/close icon, and hyperlinks inside answers &mdash; the blue accent area.</p>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <input type="color"
                                   id="auto_faq_link_color"
                                   name="auto_faq_link_color"
                                   value="<?php echo esc_attr($link_color); ?>"
                                   style="width:56px; height:40px; border:none; border-radius:8px; cursor:pointer; padding:2px;">
                            <input type="text"
                                   id="auto_faq_link_color_hex"
                                   value="<?php echo esc_attr($link_color); ?>"
                                   maxlength="7"
                                   style="width:100px; padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-family:monospace;"
                                   oninput="document.getElementById('auto_faq_link_color').value=this.value">
                        </div>
                        <script>document.getElementById('auto_faq_link_color').addEventListener('input',function(){document.getElementById('auto_faq_link_color_hex').value=this.value;});</script>
                    </div>

                    <!-- Heading Text Color -->
                    <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.08); padding:24px;">
                        <h3 style="margin-top:0; color:#5c0931; display:flex; align-items:center; gap:8px;">
                            <span style="display:inline-block; width:18px; height:18px; border-radius:50%; background:<?php echo esc_attr($heading_color); ?>; border:2px solid #e5e7eb;"></span>
                            Heading Text Color
                        </h3>
                        <p style="color:#6b7280; font-size:0.9em; margin-top:0;">Color of the &ldquo;Frequently Asked Questions&rdquo; heading above the FAQ block.</p>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <input type="color" id="auto_faq_heading_color" name="auto_faq_heading_color" value="<?php echo esc_attr($heading_color); ?>" style="width:56px; height:40px; border:none; border-radius:8px; cursor:pointer; padding:2px;">
                            <input type="text" id="auto_faq_heading_color_hex" value="<?php echo esc_attr($heading_color); ?>" maxlength="7" style="width:100px; padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-family:monospace;" oninput="document.getElementById('auto_faq_heading_color').value=this.value">
                        </div>
                        <script>document.getElementById('auto_faq_heading_color').addEventListener('input',function(){document.getElementById('auto_faq_heading_color_hex').value=this.value;});</script>
                    </div>

                    <!-- Answer Text Color -->
                    <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.08); padding:24px;">
                        <h3 style="margin-top:0; color:#5c0931; display:flex; align-items:center; gap:8px;">
                            <span style="display:inline-block; width:18px; height:18px; border-radius:50%; background:<?php echo esc_attr($answer_text_color); ?>; border:2px solid #e5e7eb;"></span>
                            Answer Text Color
                        </h3>
                        <p style="color:#6b7280; font-size:0.9em; margin-top:0;">Color of the answer body text shown when a question is expanded.</p>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <input type="color" id="auto_faq_answer_text_color" name="auto_faq_answer_text_color" value="<?php echo esc_attr($answer_text_color); ?>" style="width:56px; height:40px; border:none; border-radius:8px; cursor:pointer; padding:2px;">
                            <input type="text" id="auto_faq_answer_text_color_hex" value="<?php echo esc_attr($answer_text_color); ?>" maxlength="7" style="width:100px; padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-family:monospace;" oninput="document.getElementById('auto_faq_answer_text_color').value=this.value">
                        </div>
                        <script>document.getElementById('auto_faq_answer_text_color').addEventListener('input',function(){document.getElementById('auto_faq_answer_text_color_hex').value=this.value;});</script>
                    </div>

                    <!-- Question Text Color -->
                    <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.08); padding:24px;">
                        <h3 style="margin-top:0; color:#5c0931; display:flex; align-items:center; gap:8px;">
                            <span style="display:inline-block; width:18px; height:18px; border-radius:50%; background:<?php echo esc_attr($question_text_color); ?>; border:2px solid #e5e7eb;"></span>
                            Question Text Color
                        </h3>
                        <p style="color:#6b7280; font-size:0.9em; margin-top:0;">Color of the FAQ question text shown on each row.</p>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <input type="color" id="auto_faq_question_text_color" name="auto_faq_question_text_color" value="<?php echo esc_attr($question_text_color); ?>" style="width:56px; height:40px; border:none; border-radius:8px; cursor:pointer; padding:2px;">
                            <input type="text" id="auto_faq_question_text_color_hex" value="<?php echo esc_attr($question_text_color); ?>" maxlength="7" style="width:100px; padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-family:monospace;" oninput="document.getElementById('auto_faq_question_text_color').value=this.value">
                        </div>
                        <script>document.getElementById('auto_faq_question_text_color').addEventListener('input',function(){document.getElementById('auto_faq_question_text_color_hex').value=this.value;});</script>
                    </div>

                </div>

                <!-- Live Preview -->
                <div style="max-width:900px; margin-top:32px; background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.08); padding:24px;">
                    <h3 style="margin-top:0; color:#5c0931;">&#128065; Live Preview</h3>
                    <p style="color:#6b7280; font-size:0.9em; margin-top:0;">A sample of how your FAQ will look with the selected colors.</p>
                <div id="faq-preview" style="border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;">
                        <div id="faq-preview-q" style="padding:1.1em 1.5em; font-weight:600; font-size:1em; display:flex; justify-content:space-between; align-items:center; cursor:default;">
                            <span>What is your return policy?</span>
                            <span style="font-size:1.4em; font-weight:300;">+</span>
                        </div>
                        <div id="faq-preview-answer" style="padding:1em 1.5em; background:#fafbff; font-size:0.95em; border-top:1px solid #e5e7eb;">
                            We offer a 30-day hassle-free return on all items. <a id="faq-preview-link" href="#" onclick="return false;" style="font-weight:500; text-decoration:none;">Contact our support team</a> and we'll handle it.
                        </div>
                    </div>
                </div>

                <script>
                function updateFaqPreview() {
                    var primary  = document.getElementById('auto_faq_primary_color').value;
                    var bg       = document.getElementById('auto_faq_bg_color').value;
                    var link     = document.getElementById('auto_faq_link_color').value;
                    var heading  = document.getElementById('auto_faq_heading_color') ? document.getElementById('auto_faq_heading_color').value : primary;
                    var ansText  = document.getElementById('auto_faq_answer_text_color') ? document.getElementById('auto_faq_answer_text_color').value : '#1f2937';
                    var qText  = document.getElementById('auto_faq_question_text_color') ? document.getElementById('auto_faq_question_text_color').value : primary;
                    var q = document.getElementById('faq-preview-q');
                    if (q) {
                        q.style.background = 'linear-gradient(135deg, ' + bg + ' 0%, ' + bg + ' 100%)';
                        q.style.color = qText;
                        q.style.borderLeft = '4px solid ' + link;
                    }
                    var a = document.getElementById('faq-preview-link');
                    if (a) { a.style.color = link; }
                    var ans = document.getElementById('faq-preview-answer');
                    if (ans) { ans.style.color = ansText; }
                }
                ['auto_faq_primary_color','auto_faq_bg_color','auto_faq_link_color','auto_faq_heading_color','auto_faq_answer_text_color','auto_faq_question_text_color'].forEach(function(id){
                    var el = document.getElementById(id);
                    if (el) el.addEventListener('input', updateFaqPreview);
                });
                updateFaqPreview();
                </script>

                <?php submit_button('Save Color Settings', 'primary', 'submit', true, array('style' => 'margin-top:24px; padding:10px 28px; font-size:1em;')); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-top:8px;">
                <input type="hidden" name="action" value="auto_faq_reset_colors">
                <?php wp_nonce_field('auto_faq_reset_colors_nonce'); ?>
                <button type="submit"
                        onclick="return confirm('Reset all FAQ colors to factory defaults?');"
                        style="padding:10px 28px; font-size:1em; background:#fff; color:#b91c1c; border:2px solid #b91c1c; border-radius:4px; cursor:pointer; font-weight:600;">
                    &#8635; Reset to Defaults
                </button>
            </form>
        </div>
        <?php
    }

    public function render_analytics_page() {
        // Security: re-verify capability inside the callback.
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'auto-faq-schema'));
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'auto_faq_analytics';

        $top_faqs = $wpdb->get_results("SELECT faq_question, SUM(click_count) as total_clicks, page_url, page_title FROM $table_name GROUP BY faq_question ORDER BY total_clicks DESC LIMIT 50");
        $total_clicks = $wpdb->get_var("SELECT SUM(click_count) FROM $table_name");
        $recent_clicks = $wpdb->get_results("SELECT * FROM $table_name ORDER BY last_clicked DESC LIMIT 20");

        ?>
        <div class="wrap">
            <h1>FAQ Analytics Dashboard</h1>

            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin: 20px 0;">
                <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="margin-top: 0; color: #5c0931;">Total Clicks</h3>
                    <p style="font-size: 2em; font-weight: bold; color: #5c0931; margin: 0;"><?php echo number_format(intval($total_clicks)); ?></p>
                </div>
                <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="margin-top: 0; color: #5c0931;">Unique Questions</h3>
                    <p style="font-size: 2em; font-weight: bold; color: #5c0931; margin: 0;"><?php echo count($top_faqs); ?></p>
                </div>
                <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="margin-top: 0; color: #5c0931;">Pages with FAQs</h3>
                    <p style="font-size: 2em; font-weight: bold; color: #5c0931; margin: 0;"><?php echo count(array_unique(array_column($top_faqs, 'page_url'))); ?></p>
                </div>
            </div>

            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px;">
                <h2 style="margin-top: 0; color: #5c0931;">Top 10 Most Viewed FAQs</h2>
                <?php if (!empty($top_faqs)) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Question</th>
                                <th>Page</th>
                                <th style="width: 120px;">Clicks</th>
                                <th style="width: 150px;">Trend</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($top_faqs, 0, 10) as $index => $faq) : 
                                $percentage = $total_clicks > 0 ? round(($faq->total_clicks / $total_clicks) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td><strong><?php echo $index + 1; ?></strong></td>
                                    <td><?php echo esc_html($faq->faq_question); ?></td>
                                    <td><a href="<?php echo esc_url($faq->page_url); ?>" target="_blank"><?php echo esc_html($faq->page_title); ?></a></td>
                                    <td><strong><?php echo number_format($faq->total_clicks); ?></strong></td>
                                    <td>
                                        <div style="background: #f3f4f6; border-radius: 10px; height: 20px; overflow: hidden;">
                                            <div style="background: linear-gradient(90deg, #5c0931, #8b1247); height: 100%; width: <?php echo esc_attr((float) $percentage); ?>%; border-radius: 10px;"></div>
                                        </div>
                                        <small><?php echo esc_html((float) $percentage); ?>%</small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p style="color: #6b7280; font-style: italic;">No data yet. FAQ clicks will appear here once users start interacting with your FAQs.</p>
                <?php endif; ?>
            </div>

            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h2 style="margin-top: 0; color: #5c0931;">Recent Activity</h2>
                <?php if (!empty($recent_clicks)) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Question</th>
                                <th>Page</th>
                                <th>Clicks</th>
                                <th>Last Clicked</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_clicks as $click) : ?>
                                <tr>
                                    <td><?php echo esc_html($click->faq_question); ?></td>
                                    <td><a href="<?php echo esc_url($click->page_url); ?>" target="_blank"><?php echo esc_html($click->page_title); ?></a></td>
                                    <td><?php echo number_format($click->click_count); ?></td>
                                    <td><?php echo human_time_diff(strtotime($click->last_clicked), current_time('timestamp')) . ' ago'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p style="color: #6b7280; font-style: italic;">No recent activity to display.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function track_faq_click() {
        check_ajax_referer('auto_faq_nonce', 'nonce');

        global $wpdb;
        $table_name = $wpdb->prefix . 'auto_faq_analytics';

        $question   = isset($_POST['question'])   ? sanitize_text_field($_POST['question'])   : '';
        $page_url   = isset($_POST['page_url'])    ? esc_url_raw($_POST['page_url'])           : '';
        $page_title = isset($_POST['page_title'])  ? sanitize_text_field($_POST['page_title']) : '';

        if (empty($question) || empty($page_url)) {
            wp_send_json_error('Missing required fields.');
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, click_count FROM $table_name WHERE faq_question = %s AND page_url = %s",
            $question, $page_url
        ));

        if ($existing) {
            $wpdb->update(
                $table_name,
                array('click_count' => $existing->click_count + 1),
                array('id' => $existing->id)
            );
        } else {
            $wpdb->insert($table_name, array(
                'faq_question' => $question,
                'page_url' => $page_url,
                'page_title' => $page_title,
                'click_count' => 1,
            ));
        }

        wp_send_json_success();
    }

    public function register_block() {
        wp_register_script(
            'auto-faq-schema-editor',
            plugins_url('build/index.js', __FILE__),
            array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-block-editor', 'wp-rich-text'),
            filemtime(plugin_dir_path(__FILE__) . 'build/index.js')
        );

        wp_register_style(
            'auto-faq-schema-editor-style',
            plugins_url('build/editor.css', __FILE__),
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'build/editor.css')
        );

        wp_register_style(
            'auto-faq-schema-style',
            plugins_url('build/style.css', __FILE__),
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'build/style.css')
        );

        register_block_type('auto-faq/faq-block', array(
            'editor_script'   => 'auto-faq-schema-editor',
            'editor_style'    => 'auto-faq-schema-editor-style',
            'style'           => 'auto-faq-schema-style',
            'render_callback' => array($this, 'render_faq_block'),
            'attributes'      => array(
                'faqs' => array(
                    'type'    => 'array',
                    'default' => array(),
                    'items'   => array(
                        'type'       => 'object',
                        'properties' => array(
                            'question' => array('type' => 'string'),
                            'answer'   => array('type' => 'string'),
                        ),
                    ),
                ),
                'layout' => array(
                    'type'    => 'string',
                    'default' => 'accordion',
                ),
                'openFirst' => array(
                    'type'    => 'boolean',
                    'default' => false,
                ),
                'allowMultiple' => array(
                    'type'    => 'boolean',
                    'default' => false,
                ),
                'iconStyle' => array(
                    'type'    => 'string',
                    'default' => 'plus',
                ),
                'primaryColor' => array(
                    'type'    => 'string',
                    'default' => '#5c0931',
                ),
                'accentColor' => array(
                    'type'    => 'string',
                    'default' => '#ffd700',
                ),
                'bgColor' => array(
                    'type'    => 'string',
                    'default' => '#faf5f7',
                ),
                'trackAnalytics' => array(
                    'type'    => 'boolean',
                    'default' => true,
                ),
            ),
        ));
    }

    public function render_faq_block($attributes, $content) {
        if (empty($attributes['faqs'])) {
            return '';
        }

        $faqs = $attributes['faqs'];
        $layout = isset($attributes['layout']) ? $attributes['layout'] : 'accordion';
        $open_first = isset($attributes['openFirst']) ? $attributes['openFirst'] : false;
        $allow_multiple = isset($attributes['allowMultiple']) ? $attributes['allowMultiple'] : false;
        $icon_style = isset($attributes['iconStyle']) ? $attributes['iconStyle'] : 'plus';
        // Use global color settings as defaults; block-level attributes override them
        $global_primary      = get_option('auto_faq_primary_color',     '#5c0931');
        $global_accent       = get_option('auto_faq_accent_color',        '#ffd700');
        $global_bg           = get_option('auto_faq_bg_color',            '#faf5f7');
        $global_link         = get_option('auto_faq_link_color',          '#0ea5e9');
        $global_heading      = get_option('auto_faq_heading_color',       '#5c0931');
        $global_answer_text  = get_option('auto_faq_answer_text_color',   '#1f2937');
        $global_question_text = get_option('auto_faq_question_text_color','#5c0931');

        // Security: sanitize all color values before CSS injection.
        $raw_primary   = isset($attributes['primaryColor']) && $attributes['primaryColor'] !== '#5c0931' ? $attributes['primaryColor'] : $global_primary;
        $raw_accent    = isset($attributes['accentColor'])  && $attributes['accentColor']  !== '#ffd700' ? $attributes['accentColor']  : $global_accent;
        $raw_bg        = isset($attributes['bgColor'])      && $attributes['bgColor']      !== '#faf5f7' ? $attributes['bgColor']      : $global_bg;
        $primary_color       = sanitize_hex_color($raw_primary)           ?: '#5c0931';
        $accent_color        = sanitize_hex_color($raw_accent)            ?: '#ffd700';
        $bg_color            = sanitize_hex_color($raw_bg)                ?: '#faf5f7';
        $link_color          = sanitize_hex_color($global_link)           ?: '#0ea5e9';
        $heading_color       = sanitize_hex_color($global_heading)        ?: '#5c0931';
        $answer_text_color   = sanitize_hex_color($global_answer_text)    ?: '#1f2937';
        $question_text_color = sanitize_hex_color($global_question_text)  ?: '#5c0931';
        $track_analytics = isset($attributes['trackAnalytics']) ? $attributes['trackAnalytics'] : true;

        $this->block_count++;
        $block_id = 'auto-faq-block-' . $this->block_count;

        foreach ($faqs as $faq) {
            if (!empty($faq['question']) && !empty($faq['answer'])) {
                $this->faqs[] = array(
                    'question' => wp_kses_post($faq['question']),
                    'answer'   => wp_kses_post($faq['answer']),
                );
            }
        }

        $dynamic_css = "
            #$block_id .auto-faq-question { color: $question_text_color; background: linear-gradient(135deg, $bg_color 0%, " . $this->adjust_brightness($bg_color, -10) . " 100%); }
            #$block_id .auto-faq-question::before { background: linear-gradient(180deg, $primary_color 0%, " . $this->adjust_brightness($primary_color, 20) . " 100%); }
            #$block_id .auto-faq-question:hover { background: linear-gradient(135deg, " . $this->adjust_brightness($bg_color, -20) . " 0%, " . $this->adjust_brightness($bg_color, -30) . " 100%); color: " . $this->adjust_brightness($question_text_color, -20) . "; }
            #$block_id .auto-faq-question[aria-expanded='true'] { background: linear-gradient(135deg, $primary_color 0%, " . $this->adjust_brightness($primary_color, 20) . " 100%); color: #ffffff !important; }
            #$block_id .auto-faq-question[aria-expanded='true']::before { background: linear-gradient(180deg, $link_color 0%, " . $this->adjust_brightness($link_color, -20) . " 100%) !important; }
            #$block_id .auto-faq-question[aria-expanded='true'] .auto-faq-icon { color: $link_color !important; }
            #$block_id .auto-faq-answer { border-left-color: " . $this->adjust_brightness($bg_color, -20) . "; color: $answer_text_color; }
            #$block_id .auto-faq-answer a { color: $link_color; }
            #$block_id .auto-faq-answer a:hover { color: " . $this->adjust_brightness($link_color, -20) . "; }
            #$block_id .auto-faq-answer strong { color: $primary_color; }
            #$block_id .auto-faq-icon { color: $question_text_color; }
        ";

        ob_start();
        ?>
        <style><?php echo $dynamic_css; ?></style>
        <h2 class="auto-faq-heading" style="color:<?php echo esc_attr($heading_color); ?>; font-size:1.6em; font-weight:700; margin:0 0 0.5em 0; padding:0; text-align:center;">Frequently Asked Questions</h2>
        <div class="auto-faq-container auto-faq-layout-<?php echo esc_attr($layout); ?>" 
             id="<?php echo esc_attr($block_id); ?>"
             data-allow-multiple="<?php echo $allow_multiple ? 'true' : 'false'; ?>"
             data-track-analytics="<?php echo $track_analytics ? 'true' : 'false'; ?>">
            <?php foreach ($faqs as $index => $faq) : 
                if (empty($faq['question']) || empty($faq['answer'])) continue;
                $is_first = ($index === 0 && $open_first && $layout === 'accordion');
                $item_id = $block_id . '-item-' . $index;
                $safe_question = esc_attr(wp_strip_all_tags($faq['question']));
            ?>
                <div class="auto-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <button class="auto-faq-question" 
                            aria-expanded="<?php echo $is_first ? 'true' : 'false'; ?>" 
                            aria-controls="<?php echo esc_attr($item_id); ?>"
                            data-icon="<?php echo esc_attr($icon_style); ?>"
                            data-question="<?php echo $safe_question; ?>">
                        <span class="auto-faq-question-text" itemprop="name"><?php echo wp_kses_post($faq['question']); ?></span>
                        <span class="auto-faq-icon auto-faq-icon-<?php echo esc_attr($icon_style); ?>" aria-hidden="true">
                            <?php if ($icon_style === 'chevron') : ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            <?php elseif ($icon_style === 'arrow') : ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
                            <?php else : ?>
                                <span class="auto-faq-icon-plus-sign">+</span>
                            <?php endif; ?>
                        </span>
                    </button>
                    <div id="<?php echo esc_attr($item_id); ?>" 
                         class="auto-faq-answer<?php echo $is_first ? ' is-open' : ''; ?>" 
                         itemscope itemprop="acceptedAnswer" 
                         itemtype="https://schema.org/Answer"
                         <?php echo !$is_first ? 'hidden' : ''; ?>>
                        <div class="auto-faq-answer-inner" itemprop="text">
                            <?php echo wp_kses_post($faq['answer']); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function adjust_brightness($hex, $percent) {
        $hex = ltrim($hex, '#');
        // Security: validate hex format before processing.
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return '#5c0931'; // safe fallback
        }
        $r = max(0, min(255, hexdec(substr($hex, 0, 2)) + (int) $percent));
        $g = max(0, min(255, hexdec(substr($hex, 2, 2)) + (int) $percent));
        $b = max(0, min(255, hexdec(substr($hex, 4, 2)) + (int) $percent));
        return '#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT) . str_pad(dechex($g), 2, '0', STR_PAD_LEFT) . str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
    }

    public function output_schema() {
        if (empty($this->faqs) || $this->schema_output) {
            return;
        }

        $schema = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => array(),
        );

        foreach ($this->faqs as $faq) {
            $question = trim(wp_strip_all_tags($faq['question']));
            $answer   = trim($faq['answer']); // Google explicitly supports HTML (like <p>, <a>, <ul>) in FAQ answers!

            if (empty($question) || empty($answer)) {
                continue;
            }

            $schema['mainEntity'][] = array(
                '@type'          => 'Question',
                'name'           => $question,
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $answer,
                ),
            );
        }

        // If no valid items remain, abort output so we don't output an empty FAQPage
        if (empty($schema['mainEntity'])) {
            return;
        }

        $this->schema_output = true;
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }

    public function enqueue_frontend_assets() {
        if (!is_singular()) {
            return;
        }

        wp_enqueue_style(
            'auto-faq-schema-style',
            plugins_url('build/style.css', __FILE__),
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'build/style.css')
        );

        wp_enqueue_script(
            'auto-faq-schema-frontend',
            plugins_url('build/frontend.js', __FILE__),
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'build/frontend.js'),
            true
        );

        wp_localize_script('auto-faq-schema-frontend', 'autoFaqData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('auto_faq_nonce'),
            'pageUrl' => get_permalink(),
            'pageTitle' => get_the_title(),
        ));
    }
}

Auto_FAQ_Schema::get_instance();
