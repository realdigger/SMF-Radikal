<?php
/**
 * @package Radikal importer
 * @file radikal.php
 * @author digger <digger@mysmf.net> <https://mysmf.net>
 * @copyright Copyright (c) 2022, digger
 * @license The MIT License (MIT) https://opensource.org/licenses/MIT
 * @version 1.0b
 *
 * Обязательно делайте бэкап таблицы smf_messages перед использованием скрипта!!!
 */

// Настройки
$downloadOnly = true; // Если true, только скачать файлы, не меняя ссылки в сообщениях
$downloadFullsize = false; // Заменять миниатюры на полноразмерные изображения
//

$counterUrls = 0;
$logFile = __DIR__ . '/radikal.log.' . date('Y-m-d') . '.txt';
$cli = php_sapi_name() == 'cli';

require_once(__DIR__ . '/Settings.php');
require_once(__DIR__ . '/SSI.php');
error_reporting(E_ALL & ~E_NOTICE);

if ($cli) {
    $phpEOL = PHP_EOL;
    ob_end_clean();
} else {
    $phpEOL = '<br>';
}

if (!$cli) {
    echo '
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Импорт изображений с Radikal в SMF</title>
</head>
<body>
';
}

echo '*** Импорт изображений с Radikal в SMF' . $phpEOL;
echo '*** Обязательно сделайте бэкап таблицы smf_messages перед использованием скрипта!!!' . $phpEOL;
if ($downloadOnly) {
    echo '*** Только загрузка файлов, без внесения изменений в сообщения' . $phpEOL;
}
echo $phpEOL;

// Проверяем наличие curl
{
    if (!function_exists('curl_exec')) {
        die('* Ошибка! Для работы необходимо наличие php cURL!');
    }
}

// Открываем для записи лог файл
$log = fopen($logFile, 'a`');
fwrite($log, PHP_EOL . '***************************************************' . PHP_EOL);
fwrite($log, '*** ' . date('Y-m-d') . ' ' . date('h:i:s') . ' ***' . PHP_EOL);

// Находим сообщения с изображениями с Radikal
$result = db_query(
    "
                    SELECT ID_MSG
                    FROM {$db_prefix}messages
                    WHERE body LIKE '%radikal.ru/%'",
    __FILE__,
    __LINE__
);

echo 'Найдено сообщений со ссылками: ' . mysql_num_rows($result) . $phpEOL;

while ($row = mysql_fetch_assoc($result)) {
    $resultBody = db_query(
        "
                    SELECT body
                    FROM {$db_prefix}messages
                    WHERE ID_MSG = '" . $row['ID_MSG'] . "' LIMIT 1",
        __FILE__,
        __LINE__
    );

    list ($body) = mysql_fetch_row($resultBody);
    mysql_free_result($resultBody);

    // Находим все изображения с Radikal в этом сообщении
    $pattern = '#(?P<url>https?:\/\/[^\.\/]*.?radikal.ru\/.+)[^\d\w\/\.]#iU';
    $matchesCount = preg_match_all($pattern, $body, $matches);

    // Нечего заменять
    if (!$matchesCount) {
        continue;
    }

    foreach ($matches['url'] as $url) {
        $newUrl = processUrl($url, $row['ID_MSG']);
        if ($newUrl && $downloadOnly) {
            // Только загружаем файлы, без замены сылок в сообщениях
            $message = ' Сообщение #' . $row['ID_MSG'] . ' | ' . 'Ссылка будет заменена ' . $url . ' -> ' . $newUrl;
            echo $message . $phpEOL;
            fwrite($log, $message . PHP_EOL);
        } elseif ($newUrl) {
            // Если файл успешно загружен, заменяем ссылку в сообщении на локальную
            db_query(
                "
                    UPDATE {$db_prefix}messages
                    SET body = REPLACE(body, '" . $url . "', '" . $newUrl . "')
                    WHERE ID_MSG = '" . $row['ID_MSG'] . "' LIMIT 1",
                __FILE__,
                __LINE__
            );


            // Если замена успешна
            if (db_affected_rows() != 0) {
                $message = ' Сообщение #' . $row['ID_MSG'] . ' | ' . 'Ссылка заменена ' . $url . ' -> ' . $newUrl;
                $counterUrls++;
            } else {
                // Иначе ошибка
                $message = '[ERROR] Сообщение #' . $row['ID_MSG'] . ' | ' . 'Ошибка замены ссылки ' . $url . ' -> ' . $newUrl;
            }

            echo $message . $phpEOL;
            fwrite($log, $message . PHP_EOL);
        }
    }
}

mysql_free_result($result);

$message = '*** Импорт завершен. Обработано ссылок: ' . $counterUrls;
echo $phpEOL . $message . $phpEOL;
fwrite($log, $message . PHP_EOL);
fclose($log);

if (!$cli) {
    echo '
</body>
</html>';
}


/**
 * Обработка каждой ссылки на Radikal в сообщении
 *
 * @param $url
 * @param $msgId
 * @return bool
 */
function processUrl($url, $msgId)
{
    global $boardurl, $log, $phpEOL;

    $host = str_replace('.radikal.ru', '', strtolower(parse_url($url, PHP_URL_HOST)));
    $host = str_replace('radikal.ru', '000', $host);
    $urlPath = parse_url($url, PHP_URL_PATH);
    $path = pathinfo($urlPath)['dirname'];
    $file = pathinfo($urlPath)['basename'];
    $dir = __DIR__ . '/radikal/' . $host . $path;
    @mkdir($dir, 0777, true);


    // Загружаем файл
    if (is_dir($dir) && downloadFile($url, $dir . '/' . $file, $msgId)) {
        return $boardurl . '/radikal/' . $host . $path . '/' . $file;
    }

    $message = ' Сообщение #' . $msgId . ' | ' . $url . ' | ' . 'Ошибка сохранения файла';
    echo $message . $phpEOL;
    fwrite($log, $message . PHP_EOL);

    return false;
}

/**
 * Загрузка файла в заданный каталог
 *
 * @param $url
 * @param $filePath
 * @param $msgId
 * @return bool
 */
function downloadFile($url, $filePath, $msgId)
{
    global $phpEOL, $log;
    $error = false;

    // Если уже загружен, пропускаем
    if (file_exists($filePath)) {
        $message = ' Сообщение #' . $msgId . ' | ' . $url . ' | ' . ' Уже загружен';
        echo $message . $phpEOL;
        fwrite($log, $message . PHP_EOL);
        return true;
    }

    $file = fopen($filePath, 'w');
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_FILE, $file);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($curl);

    if (curl_error($curl)) {
        echo '[ERROR] Ошибка загрузки: ' . curl_error($curl) . $phpEOL;
        $error = true;
    }

    $info = curl_getinfo($curl);
    // Проверяем код ответа сервера
    if ($info['http_code'] !== 200 && $info['http_code'] !== 301 && $info['http_code'] !== 302) {
        $error = true;
    }

    // Записываем в лог про каждую ссылку
    $message = ' Сообщение #' . $msgId . ' | ' . $url . ' | ' . $info['http_code'];
    echo $message . $phpEOL;
    fwrite($log, $message . PHP_EOL);

    curl_close($curl);
    fclose($file);

    if (file_exists($filePath) && !$error) {
        return true;
    } else {
        return false;
    }
}