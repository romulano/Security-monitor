<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Auth\GithubCallbackRequest;

/** A volta do GitHub na reautenticação: o mesmo state e o mesmo código da volta do login. */
class GithubReauthCallbackRequest extends GithubCallbackRequest {}
