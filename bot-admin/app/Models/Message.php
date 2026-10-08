<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $table = 'messages';

    protected $fillable = [
        'user_id',
        'role',
        'content',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Аксессор для metadata - преобразует массив в читаемый JSON
     */
    public function getMetadataAttribute($value)
    {
        if (is_null($value)) {
            return null;
        }

        // Если это уже массив
        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        // Если это строка JSON
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        // Если это обычная строка
        return $value;
    }

    /**
     * Мутатор для metadata - сохраняет массив как JSON
     */
    public function setMetadataAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['metadata'] = json_encode($value, JSON_UNESCAPED_UNICODE);
        } else {
            $this->attributes['metadata'] = $value;
        }
    }

    /**
     * Получить metadata как массив
     */
    public function getMetadataArray()
    {
        $value = $this->attributes['metadata'] ?? null;

        if (is_null($value)) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return ['raw' => $value];
    }
}