<?php

namespace OPNsense\Base;

class BaseModel
{
    public function getNodes(): array
    {
        $path = getenv('RUNTIME_TEST_MODEL');
        if ($path === false || $path === '' || !is_readable($path)) {
            throw new \RuntimeException('Тестовая MVC-модель недоступна.');
        }

        $content = file_get_contents($path);
        $nodes = $content === false ? null : json_decode($content, true);
        if (!is_array($nodes)) {
            throw new \RuntimeException('Тестовая MVC-модель имеет некорректный формат.');
        }

        return $nodes;
    }
}
