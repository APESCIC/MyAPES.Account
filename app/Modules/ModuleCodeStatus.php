<?php

namespace App\Modules;

enum ModuleCodeStatus: string
{
    case Shipped = 'shipped';
    case CodeNotShipped = 'code_not_shipped';
    case Incompatible = 'incompatible';

    public function label(): string
    {
        return match ($this) {
            self::Shipped => __('admin.plugins.code_status.shipped'),
            self::CodeNotShipped => __('admin.plugins.code_status.code_not_shipped'),
            self::Incompatible => __('admin.plugins.code_status.incompatible'),
        };
    }
}
