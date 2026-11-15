<?php

namespace App\Http\Requests\Admin\Alerts\Questions;

use App\Models\InterviewQuestion;

class UpdateQuestionRequest extends QuestionRequest
{
    protected function editing(): ?InterviewQuestion
    {
        return $this->route('question');
    }
}
