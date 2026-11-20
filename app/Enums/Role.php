<?php

namespace App\Enums;

enum Role: string
{
    case Requester = 'requester';
    case Technician = 'technician';
}
