<?php

namespace App\Observers;

use App\Models\Expense;

class ExpenseObserver
{
    public function created(Expense $expense): void
    {
        //
    }
}
