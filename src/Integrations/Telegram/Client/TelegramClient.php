<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Integrations\Telegram\Client;

use NRPro\Pasargad\Core\Config\Config;
use RuntimeException;

final class TelegramClient
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Get bot information.
     */
    public function getMe(): array
    {
        $result = $this->request('getMe', []);

        if (!is_array($result)) {
            throw new RuntimeException(
                'Invalid Telegram getMe response.'
            );
        }

        return $result;
    }

    /**
     * Send a text message with optional inline keyboard.
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?array $replyMarkup = null
    ): array {

        self::checkMessage($chatId, $text);

        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($replyMarkup !== null) {

            self::checkKeyboard($replyMarkup);

            $params['reply_markup'] = $replyMarkup;
        }

        $result = $this->request(
            'sendMessage',
            $params
        );

        if (
            !is_array($result) ||
            !is_int($result['message_id'] ?? null)
        ) {
            throw new RuntimeException(
                'Invalid Telegram sendMessage response.'
            );
        }

        return $result;
    }

    /**
     * Edit an existing bot message.
     */
    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        ?array $replyMarkup = null
    ): array {

        self::checkMessage($chatId, $text);

        if ($messageId <= 0) {
            throw new RuntimeException(
                'Invalid Telegram message ID.'
            );
        }

        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];

        if ($replyMarkup !== null) {

            self::checkKeyboard($replyMarkup);

            $params['reply_markup'] = $replyMarkup;
        }

        $result = $this->request(
            'editMessageText',
            $params
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'Invalid Telegram editMessageText response.'
            );
        }

        return $result;
    }

    /**
     * Acknowledge an inline button click.
     */
    public function answerCallbackQuery(
        string $callbackId,
        string $text = '',
        bool $showAlert = false
    ): bool {

        if (
            $callbackId === '' ||
            strlen($callbackId) > 256 ||
            preg_match('/[\x00-\x1F\x7F]/', $callbackId)
        ) {
            throw new RuntimeException(
                'Invalid Telegram callback query ID.'
            );
        }

        if (
            strlen($text) > 800 ||
            preg_match(
                '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
                $text
            )
        ) {
            throw new RuntimeException(
                'Invalid Telegram callback answer.'
            );
        }

        $params = [
            'callback_query_id' => $callbackId,
            'show_alert' => $showAlert,
        ];

        if ($text !== '') {
            $params['text'] = $text;
        }

        return $this->request(
            'answerCallbackQuery',
            $params
        ) === true;
    }

    /**
     * Validate outgoing message.
     */
    private static function checkMessage(
        int|string $chatId,
        string $text
    ): void {

        if (
            (is_int($chatId) && $chatId === 0) ||
            (
                is_string($chatId) &&
                (
                    $chatId === '' ||
                    strlen($chatId) > 128
                )
            ) ||
            trim($text) === '' ||
            strlen($text) > 16000
        ) {
            throw new RuntimeException(
                'Invalid Telegram message arguments.'
            );
        }
    }

    /**
     * Validate an inline keyboard.
     */
    private static function checkKeyboard(
        array $markup
    ): void {

        if (
            array_keys($markup) !== ['inline_keyboard'] ||
            !is_array($markup['inline_keyboard']) ||
            !array_is_list($markup['inline_keyboard']) ||
            count($markup['inline_keyboard']) > 20
        ) {
            throw new RuntimeException(
                'Invalid Telegram inline keyboard.'
            );
        }

        foreach ($markup['inline_keyboard'] as $row) {

            if (
                !is_array($row) ||
                !array_is_list($row) ||
                $row === [] ||
                count($row) > 8
            ) {
                throw new RuntimeException(
                    'Invalid Telegram keyboard row.'
                );
            }

            foreach ($row as $button) {

                if (
                    !is_array($button) ||
                    array_keys($button) !== [
                        'text',
                        'callback_data'
                    ]
                ) {
                    throw new RuntimeException(
                        'Invalid Telegram keyboard button.'
                    );
                }

                $text = $button['text'];
                $data = $button['callback_data'];

                if (
                    !is_string($text) ||
                    trim($text) === '' ||
                    strlen($text) > 256 ||
                    !is_string($data) ||
                    strlen($data) < 1 ||
                    strlen($data) > 64 ||
                    preg_match(
                        '/[\x00-\x1F\x7F]/',
                        $data
                    )
                ) {
                    throw new RuntimeException(
                        'Invalid Telegram keyboard button content.'
                    );
                }
            }
        }
    }

    /**
     * Send Telegram API request.
     */
    private function request(
        string $method,
        array $parameters
    ): array|bool {

        $token = (string) $this->config->get(
            'telegram.bot_token',
            ''
        );

        if (
            !preg_match(
                '/^[0-9]+:[A-Za-z0-9_-]{20,}$/D',
                $token
            )
        ) {
            throw new RuntimeException(
                'TELEGRAM_BOT_TOKEN is missing or invalid.'
            );
        }

        if (!extension_loaded('curl')) {
            throw new RuntimeException(
                'PHP cURL extension is not enabled.'
            );
        }

        $url = 'https://api.telegram.org/bot'
            . $token
            . '/'
            . $method;

        $body = json_encode(
            $parameters,
            JSON_THROW_ON_ERROR |
            JSON_UNESCAPED_UNICODE
        );

        /*
         * HTTPS certificate configuration.
         */
        $caBundle = getenv('TELEGRAM_CA_BUNDLE');

        $caBundle = is_string($caBundle)
            ? trim($caBundle)
            : '';

        if (
            $caBundle === '' &&
            PHP_OS_FAMILY === 'Windows'
        ) {

            $localAppData = getenv('LOCALAPPDATA');

            if (
                is_string($localAppData) &&
                $localAppData !== ''
            ) {

                $candidate = $localAppData
                    . DIRECTORY_SEPARATOR
                    . 'NR-PRO-PASARGAD'
                    . DIRECTORY_SEPARATOR
                    . 'certs'
                    . DIRECTORY_SEPARATOR
                    . 'cacert.pem';

                if (is_file($candidate)) {
                    $caBundle = $candidate;
                }
            }
        }

        if (
            $caBundle !== '' &&
            (
                !is_file($caBundle) ||
                !is_readable($caBundle)
            )
        ) {
            throw new RuntimeException(
                'Telegram trusted CA bundle is not readable.'
            );
        }

        /*
         * Proxy configuration.
         */
        $proxy = '';

        $proxyVariables = [
            'TELEGRAM_HTTPS_PROXY',
            'HTTPS_PROXY',
            'https_proxy',
            'HTTP_PROXY',
            'http_proxy',
        ];

        foreach ($proxyVariables as $name) {

            $value = getenv($name);

            if (
                is_string($value) &&
                trim($value) !== ''
            ) {
                $proxy = trim($value);
                break;
            }
        }

        if (
            $proxy !== '' &&
            (
                preg_match('/[\r\n]/', $proxy) ||
                !preg_match(
                    '~^(?:https?|socks5h?)://[^\s]+$~i',
                    $proxy
                )
            )
        ) {
            throw new RuntimeException(
                'Invalid Telegram proxy URL.'
            );
        }

        /*
         * Initialize HTTPS request.
         */
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to initialize Telegram HTTPS request.'
            );
        }

        try {

            $options = [

                CURLOPT_POST => true,

                CURLOPT_POSTFIELDS => $body,

                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],

                CURLOPT_RETURNTRANSFER => true,

                CURLOPT_FOLLOWLOCATION => false,

                CURLOPT_CONNECTTIMEOUT => 5,

                CURLOPT_TIMEOUT => 12,

                CURLOPT_SSL_VERIFYPEER => true,

                CURLOPT_SSL_VERIFYHOST => 2,

                CURLOPT_USERAGENT => 'NR-PRO-BOT/21B',
            ];

            if ($caBundle !== '') {
                $options[CURLOPT_CAINFO] = $caBundle;
            }

            if ($proxy !== '') {
                $options[CURLOPT_PROXY] = $proxy;
            }

            if (!curl_setopt_array($handle, $options)) {
                throw new RuntimeException(
                    'Unable to configure Telegram HTTPS request.'
                );
            }

            $response = curl_exec($handle);

            $status = (int) curl_getinfo(
                $handle,
                CURLINFO_RESPONSE_CODE
            );

            $curlCode = curl_errno($handle);

        } finally {

            curl_close($handle);
        }

        /*
         * Do not expose token, URL or raw cURL errors.
         */
        if ($response === false) {
            throw new RuntimeException(
                'Telegram HTTPS transport error (cURL code '
                . $curlCode
                . ').'
            );
        }

        if (
            $status < 200 ||
            $status >= 300
        ) {
            throw new RuntimeException(
                'Telegram Bot API HTTP error '
                . $status
                . '.'
            );
        }

        try {

            $decoded = json_decode(
                (string) $response,
                true,
                32,
                JSON_THROW_ON_ERROR
            );

        } catch (\JsonException) {

            throw new RuntimeException(
                'Telegram Bot API returned invalid JSON.'
            );
        }

        if (
            !is_array($decoded) ||
            ($decoded['ok'] ?? null) !== true
        ) {
            throw new RuntimeException(
                'Telegram Bot API reported an unsuccessful response.'
            );
        }

        $result = $decoded['result'] ?? null;

        if (
            $result !== true &&
            !is_array($result)
        ) {
            throw new RuntimeException(
                'Telegram Bot API returned invalid result.'
            );
        }

        return $result;
    }
}