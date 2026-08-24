<?php

/*
 * Copyright (C) 2014-2026 Deciso B.V.
 * Copyright (C) 2010 Erik Fonnesbeck
 * Copyright (C) 2008-2010 Ermal Luči
 * Copyright (C) 2004-2008 Scott Ullrich <sullrich@gmail.com>
 * Copyright (C) 2006 Daniel S. Haischt
 * Copyright (C) 2003-2004 Manuel Kasper <mk@neon1.net>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

const LOG_FILE = '/var/log/sing-box/sing-box.log';
const LOG_TAIL_LINES = 200;
const LOG_MAX_BYTES = 262144;

header('Content-Type: text/plain; charset=UTF-8');

function readLogTail($path, $maxLines, $maxBytes)
{
    if (!is_file($path)) {
        return '[Информация] Журнал sing-box пока не создан.';
    }

    if (!is_readable($path)) {
        return '[Ошибка] Журнал sing-box недоступен для чтения.';
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return '[Ошибка] Не удалось открыть журнал sing-box.';
    }

    $size = filesize($path);
    if ($size === false) {
        fclose($handle);
        return '[Ошибка] Не удалось определить размер журнала sing-box.';
    }

    $readSize = min($size, $maxBytes);
    if ($readSize <= 0) {
        fclose($handle);
        return '';
    }

    if (fseek($handle, -$readSize, SEEK_END) !== 0) {
        fclose($handle);
        return '[Ошибка] Не удалось прочитать конец журнала sing-box.';
    }

    $content = fread($handle, $readSize);
    fclose($handle);

    if ($content === false || $content === '') {
        return '';
    }

    $lines = preg_split('/\r\n|\n|\r/', $content);
    if ($size > $readSize && !empty($lines)) {
        array_shift($lines);
    }

    return implode("\n", array_slice($lines, -$maxLines));
}

echo readLogTail(LOG_FILE, LOG_TAIL_LINES, LOG_MAX_BYTES);
