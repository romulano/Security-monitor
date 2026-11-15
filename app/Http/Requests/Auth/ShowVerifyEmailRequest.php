<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Request;

/** O aviso "confirme o seu e-mail" é de quem está logado. */
class ShowVerifyEmailRequest extends Request {}
