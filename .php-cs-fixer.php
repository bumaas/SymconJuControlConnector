<?php

declare(strict_types=1);

/**
 * Schlankes Regelwerk für php-cs-fixer — bewusst NICHT das volle StylePHP von Symcon.
 *
 * Aufgenommen sind nur Regeln, die echte Mängel beheben und keine gewachsene Ordnung
 * antasten. Bewusst ausgelassen (Messung 09.09.2026 am gesamten Repo):
 *
 *  - ordered_class_elements  — sortiert die Klasse nach Sichtbarkeit um (558 Zeilen allein
 *    in module.php); reißt inhaltlich zusammengehörige Methoden auseinander und entwertet
 *    `git blame`.
 *  - binary_operator_spaces  — entfernt die ausgerichteten Zuweisungsspalten (196 Zeilen);
 *    ausgerichtete Konstantenblöcke sind hier bewusst so geschrieben und besser lesbar.
 *  - cast_spaces, single_space_around_construct, function_declaration, method_argument_space
 *    — Geschmacksfragen ((string)$x → (string) $x, fn( → fn ().
 *
 * Mit dieser Auswahl bleibt das Repo dauerhaft grün: der volle Symcon-Stil würde rund
 * 1.800 Zeilen umformatieren, diese Auswahl rund 250 — davon der größte Teil einmalig
 * Zeilenenden. Siehe Punkt 7 der Referenz-Checkliste (keine Massen-Umformatierung).
 *
 * Aufruf: php php-cs-fixer.phar fix --dry-run --diff   (ohne --dry-run wird korrigiert)
 */

$finder = PhpCsFixer\Finder::create()
    ->exclude('tests/stubs') // Kernel-Stub von symcon/SymconStubs, fremder Code
    ->exclude('docs')
    ->in(__DIR__);

return (new PhpCsFixer\Config())
    ->setRules([
        'align_multiline_comment'          => ['comment_type' => 'all_multiline'],
        'array_indentation'                => true,
        'blank_line_after_opening_tag'     => true,
        'line_ending'                      => true,
        'no_blank_lines_after_class_opening' => true,
        'no_extra_blank_lines'             => true,
        'no_trailing_whitespace'           => true,
        'no_unneeded_control_parentheses'  => true,
        'single_quote'                     => true,
        'statement_indentation'            => true,
    ])
    ->setFinder($finder);
