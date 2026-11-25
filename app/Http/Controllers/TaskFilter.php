<?php

namespace App\Http\Controllers;

enum TaskFilter: string
{
    case Open = 'open';
    case Overdue = 'overdue';
    case Completed = 'completed';
}
