<?php
/**
 * @package Radikal importer for SMF2
 * @file radikal.php
 * @author digger <digger@mysmf.net> <https://mysmf.net>
 * @copyright Copyright (c) 2022, digger
 * @license The MIT License (MIT) https://opensource.org/licenses/MIT
 * @version 1.0b
 *
 * Обязательно делайте бэкап таблицы smf_messages перед использованием скрипта!!!
 */

// Настройки
$downloadOnly     = true;  // Если true, только скачать файлы, не меняя ссылки в сообщениях
$downloadFullsize = false; // Если true, заменять миниатюры на полноразмерные изображения
//$downloadBroken   = true;  // Если true, создавать пустые файлы вместо отсутствующих на фотохостинге, заглушек, ошибок сервера (повторных попыток скачать такие файлы делаться не будет). Если false, при каждом запуске будет заново пытаться скачать такие файлы.
//

$counterMessages       = 0; // Сообщений со ссылками
$counterUrls           = 0; // Найдено ссылок
$counterUrlsFixed      = 0; // Заменено ссылок
$counterTumbnails      = 0; // Найдено ссылок на миниатюры
$counterDownloadErrors = 0; // Ошибок загрузки файлов
$logFile               = __DIR__ . '/radikal.log.' . date('Y-m-d') . '.txt';
$cli                   = php_sapi_name() == 'cli';

require_once(__DIR__ . '/Settings.php');
require_once(__DIR__ . '/SSI.php');
error_reporting(E_ALL);

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
} else {
    echo '*** Будут загружены файлы и заменены сылки в сообщениях' . $phpEOL;
}
if ($downloadFullsize) {
    echo '*** Миниатюры будут заменяться на полноразмерные изображения' . $phpEOL;
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
$result = $smcFunc['db_query']('', '
					SELECT id_msg 
					FROM {db_prefix}messages
					WHERE body LIKE "%radikal.ru/%"',
                               []
);

$counterMessages = $smcFunc['db_num_rows'] ($result);
$message = 'Найдено сообщений со ссылками: ' . $counterMessages++;
echo $message . $phpEOL;
fwrite($log, $message . PHP_EOL);
sleep(10);

while ($row = $smcFunc['db_fetch_assoc']($result)) {
    $counterMessages--;
    $resultBody = @$smcFunc['db_query']('', '
					SELECT body 
					FROM {db_prefix}messages
					WHERE id_msg = {int:id_msg}
					LIMIT 1',
                                        ['id_msg' => $row['id_msg']]
    );

    $body = @$smcFunc['db_fetch_row']($resultBody)[0];
    $smcFunc['db_free_result']($resultBody);

    // Находим все изображения с Radikal в этом сообщении
    $pattern      = '#(?P<url>https?:\/\/[^\.\/]*.?radikal.ru\/.+)[^\d\w\/\.]#iU';
    $matchesCount = preg_match_all($pattern, $body, $matches);

    // Нечего заменять
    if (!$matchesCount) {
        continue;
    }

    foreach ($matches['url'] as $url) {
        $counterUrls++;
        $newUrl = processUrl($url, $row['id_msg']);

        if ($newUrl && $downloadOnly) {
            // Только загружаем файлы, без замены сылок в сообщениях
            $message = ' ' . $counterMessages . ' | Сообщение #' . $row['id_msg'] . ' | ' . 'Ссылка будет заменена ' . $url . ' -> ' . $newUrl;
            echo $message . $phpEOL;
            fwrite($log, $message . PHP_EOL);
        } elseif ($newUrl) {
            // Если файл успешно загружен, заменяем ссылку в сообщении на локальную
            $smcFunc['db_query']('', '
                    UPDATE {db_prefix}messages
					SET body = REPLACE(body, {string:old_url}, {string:new_url})
					WHERE id_msg = {int:id_msg}
					LIMIT 1',
                                 [
                                     'id_msg'  => $row['id_msg'],
                                     'old_url' => $url,
                                     'new_url' => $newUrl,
                                 ]
            );

            // Если замена успешна
            if ($smcFunc['db_affected_rows']() != 0) {
                $message = ' ' . $counterMessages . ' | Сообщение #' . $row['id_msg'] . ' | ' . 'Ссылка заменена ' . $url . ' -> ' . $newUrl;
                $counterUrlsFixed++;
            } else {
                // Иначе ошибка
                $message = '[ERROR] Сообщение #' . $row['id_msg'] . ' | ' . 'Ошибка замены ссылки ' . $url . ' -> ' . $newUrl;
            }

            echo $message . $phpEOL;
            fwrite($log, $message . PHP_EOL);
        }
    }
}

$smcFunc['db_free_result']($result);

$message = '
*** Импорт завершен.
*** Найдено ссылок: ' . $counterUrls . '
*** Из них миниатюр: ' . $counterTumbnails . '
*** Заменено в сообщениях: ' . $counterUrlsFixed . '
*** Ошибок загрузки файлов: ' . $counterDownloadErrors;;

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
    global $boardurl, $log, $phpEOL, $downloadFullsize, $counterMessages, $counterTumbnails, $counterDownloadErrors;

    $host    = str_replace('.radikal.ru', '', strtolower(parse_url($url, PHP_URL_HOST)));
    $host    = str_replace('radikal.ru', '000', $host);
    $urlPath = parse_url($url, PHP_URL_PATH);
    $path    = pathinfo($urlPath)['dirname'];
    $file    = pathinfo($urlPath)['basename'];

    // Считаем миниатюры
    if (strpos($file, 't.') !== false) {
        $counterTumbnails++;
    }

    // Заменяем миниатюру на полноразмерную, если задано
    if ($downloadFullsize) {
        $file = str_replace('t.', '.', $file);
        $url  = str_replace('t.', '.', $url);
    }

    $dir = __DIR__ . '/radikal/' . $host . $path;
    @mkdir($dir, 0777, true);

    // Загружаем файл
    if (is_dir($dir) && downloadFile($url, $dir . '/' . $file, $msgId)) {
        return $boardurl . '/radikal/' . $host . $path . '/' . $file;
    }

    $counterDownloadErrors++;
    $message = ' ' . $counterMessages . ' | Сообщение #' . $msgId . ' | ' . $url . ' | ' . 'Ошибка сохранения файла';
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
    static $curl;

    global $phpEOL, $log, $counterMessages, $counterDownloadErrors;
    $error = false;

    // Если уже загружен, пропускаем
    // TODO проверить, что не битый
    if (file_exists($filePath)) {
        $message = ' ' . $counterMessages . ' | Сообщение #' . $msgId . ' | ' . $url . ' | ' . ' Уже загружен: ' . round(
                filesize($filePath) / 1024,
                2
            ) . ' Кб';
        echo $message . $phpEOL;
        fwrite($log, $message . PHP_EOL);
        return true;
    }

    if (!$curl) {
        $curl = curl_init();
    }

    $file = fopen($filePath, 'w');
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_FILE, $file);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

    curl_exec($curl);

    if (curl_error($curl)) {
        echo '[ERROR] Ошибка загрузки: ' . curl_error($curl) . $phpEOL;
        $error = true;
    }

    $info = curl_getinfo($curl);
    // Проверяем код ответа сервера
    if ($info['http_code'] !== 200 && $info['http_code'] !== 301 && $info['http_code'] !== 302) {
        $error = true;
        $counterDownloadErrors++;
    }

    // Записываем в лог про каждую ссылку
    $message = ' ' . $counterMessages . ' | Сообщение #' . $msgId . ' | ' . $url . ' | Ответ сервера: ' . $info['http_code'];
    echo $message . $phpEOL;
    fwrite($log, $message . PHP_EOL);

    // curl_close($curl);
    fclose($file);

    // TODO Проверять что скачалась не заглушка
    // Если файл пустой или html и не задано $downloadBroken, то
    // unlink $filePath

    if (file_exists($filePath) && !$error) {
        return true;
    } else {
        return false;
    }
}
