<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Kirschbaum\Commentions\Models\Comment as PackageComment;

/**
 * Comentario (kirschbaum-development/commentions) con id UUID: la tabla `comments` lo tiene uuid para poder fusionar bases
 * (config/commentions.php -> models.comment). Sin esto el modelo del paquete trataria el id como numerico.
 */
class Comment extends PackageComment
{
    use HasUuids;
}
