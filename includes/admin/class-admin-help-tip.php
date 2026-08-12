<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders contextual ? help tips for admin cards and forms.
 */
class Rmmigrate_Admin_Help_Tip
{
    /**
     * @param string[] $lines
     */
    public static function render(array $lines, string $aria_label, string $extra_class = ''): void
    {
        if ($lines === array()) {
            return;
        }

        $classes = trim('mm-help-tip ' . $extra_class);
        echo '<span class="' . esc_attr($classes) . '" tabindex="0" role="button" aria-expanded="false" aria-label="' . esc_attr($aria_label) . '">';
        echo '<span class="mm-help-tip__mark" aria-hidden="true">?</span>';
        echo '<template class="mm-help-tip-template">';
        $html_lines = array();
        foreach ($lines as $line) {
            $html_lines[] = self::line_html((string) $line);
        }
        echo wp_kses(
            implode('<br>', $html_lines),
            array(
                'strong' => array(),
                'br'     => array(),
            )
        );
        echo '</template>';
        echo '</span>';
    }

    public static function line_html(string $line): string
    {
        if (preg_match('/^(\.[\w]+)(\s*—\s*)(.+)$/u', $line, $matches)) {
            return '<strong>' . esc_html($matches[1]) . '</strong>' . esc_html($matches[2] . $matches[3]);
        }

        return esc_html($line);
    }
}
