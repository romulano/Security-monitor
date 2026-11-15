<?php

namespace App\Http\Requests\Alerts\Github;

use App\Http\Requests\Alerts\FeedbackRequest;

/** O motivo escrito na página do link assinado: a assinatura (middleware `signed`) substitui o login. */
class SignedGithubNoteRequest extends FeedbackRequest {}
