<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Base dos form requests: cada controller que lê algo da requisição recebe o
 * seu. Quando o authorize() recusa (o registro não é de quem pediu), a
 * resposta é 404 e não 403, para não revelar que o registro existe.
 */
abstract class Request extends FormRequest
{
    protected function failedAuthorization(): never
    {
        throw new NotFoundHttpException;
    }
}
