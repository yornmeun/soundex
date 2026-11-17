<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidSrtException extends RuntimeException
{
    public static function empty(): self
    {
        return new self('The subtitle file is empty.');
    }

    public static function noCues(): self
    {
        return new self('No valid subtitles were found. Please upload a standard .srt file (index, "00:00:01,000 --> 00:00:02,000" timestamp, text).');
    }

    public static function noText(): self
    {
        return new self('The subtitle file contains timestamps but no subtitle text.');
    }

    public static function unreadableEncoding(): self
    {
        return new self('The file encoding could not be detected. Please save the subtitle file as UTF-8 and try again.');
    }
}
