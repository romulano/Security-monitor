<?php

namespace App\Http\Requests\Admin\Alerts\Questions;

use App\Models\InterviewQuestion;

class StoreQuestionRequest extends QuestionRequest
{
    protected function editing(): ?InterviewQuestion
    {
        return null;
    }
}
