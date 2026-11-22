<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

// Internal base: controllers must pass validated, explicitly selected attributes.
abstract class Record extends Model
{
    protected $guarded = ['id'];
}
