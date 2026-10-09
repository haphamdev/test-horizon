<?php

namespace App\Enums;

enum Priority: string
{
    case Critical = 'critical';
    case Transactional = 'transactional';
    case Bulk = 'bulk';

    /**
     * The queue name that carries work for this priority tier.
     */
    public function queue(): string
    {
        return $this->value;
    }
}
