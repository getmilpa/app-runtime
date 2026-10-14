<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Telegram;

/**
 * Every word this surface says to a person, English by default and Spanish beside it (greenhouse decisions/0138).
 *
 * The same shape as the panel's catalogue and for the same reason it is not shared: a surface names its own
 * words, and this one must work in a house with no panel package to borrow them from.
 */
final class Catalog
{
    public const DEFAULT_LOCALE = 'en';

    private const MESSAGES = [
        'en' => [
            'card.frontier' => 'A seat\'s call was refused: it lacks %s (%s).',
            'card.frontier.bare' => 'A seat\'s call was refused for a scope it lacks.',
            'card.question' => 'A session is waiting for your answer about %s.',
            'card.question.bare' => 'A session is waiting for your answer.',
            'card.footer' => 'Read it and decide in the house, with your passkey. This message decides nothing.',
            'card.expires' => 'The link opens once and lasts %s min.',
            'card.button' => 'Open the house',
            'done.granted' => 'Decided: %s granted %s.',
            'done.answered' => 'Decided: %s answered %s.',
            'done.answered.bare' => 'Decided: %s answered.',
            'done.gone' => 'No longer waiting: it was settled, or the session moved on.',
            'link.expired' => 'The link expired. It still waits for you in the house\'s panel.',
            'link.panel' => 'Open the panel',
            'page.title' => 'Milpa',
            'page.invalid' => 'This link is not valid any more. What waits for you is in the house\'s panel.',
        ],
        'es' => [
            'card.frontier' => 'A un asiento se le rehusó una llamada: le falta %s (%s).',
            'card.frontier.bare' => 'A un asiento se le rehusó una llamada por un alcance que no tiene.',
            'card.question' => 'Una sesión espera tu respuesta sobre %s.',
            'card.question.bare' => 'Una sesión espera tu respuesta.',
            'card.footer' => 'Léelo y decide en la casa, con tu passkey. Este mensaje no decide nada.',
            'card.expires' => 'El enlace abre una vez y dura %s min.',
            'card.button' => 'Abrir la casa',
            'done.granted' => 'Decidido: %s concedió %s.',
            'done.answered' => 'Decidido: %s contestó %s.',
            'done.answered.bare' => 'Decidido: %s contestó.',
            'done.gone' => 'Ya no espera: se resolvió, o la sesión siguió su camino.',
            'link.expired' => 'El enlace caducó. Sigue esperándote en el panel de la casa.',
            'link.panel' => 'Abrir el panel',
            'page.title' => 'Milpa',
            'page.invalid' => 'Este enlace ya no es válido. Lo que te espera está en el panel de la casa.',
        ],
    ];

    private readonly string $locale;

    public function __construct(string $locale = self::DEFAULT_LOCALE)
    {
        $this->locale = isset(self::MESSAGES[$locale]) ? $locale : self::DEFAULT_LOCALE;
    }

    /** The message for a key in this locale, falling back to English and then to the key itself. */
    public function tr(string $key, string ...$args): string
    {
        $text = self::MESSAGES[$this->locale][$key] ?? self::MESSAGES[self::DEFAULT_LOCALE][$key] ?? $key;

        return $args === [] ? $text : vsprintf($text, $args);
    }

    /** The locale in use — the default when the one asked for is not a catalogue. */
    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * The locales this catalogue speaks.
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        return array_keys(self::MESSAGES);
    }

    /**
     * Every key of one locale — what a test holds the two catalogues to.
     *
     * @return list<string>
     */
    public static function keys(string $locale): array
    {
        return array_keys(self::MESSAGES[$locale] ?? []);
    }
}
