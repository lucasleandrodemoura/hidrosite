<?php
declare(strict_types=1);

namespace ValeTaquari;

class Logger
{
    private string $path;
    private string $level;

    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public function __construct(string $path, string $level = 'info')
    {
        $this->path  = $path;
        $this->level = $level;

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function info(string $msg, array $ctx = []): void
    {
        $this->write('INFO', $msg, $ctx);
    }

    public function warning(string $msg, array $ctx = []): void
    {
        $this->write('WARNING', $msg, $ctx);
    }

    public function error(string $msg, array $ctx = []): void
    {
        $this->write('ERROR', $msg, $ctx);
    }

    public function debug(string $msg, array $ctx = []): void
    {
        if ((self::LEVELS[$this->level] ?? 1) === 0) {
            $this->write('DEBUG', $msg, $ctx);
        }
    }

    private function write(string $lvl, string $msg, array $ctx): void
    {
        $ts   = date('Y-m-d H:i:s');
        $line = "[{$ts}] [{$lvl}] {$msg}";
        if (!empty($ctx)) {
            $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE);
        }
        file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
