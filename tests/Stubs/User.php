<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Minimální náhrada User modelu host aplikace pro testy balíčku.
 *
 * Balíček host User model neimportuje — čte ho z `config('chatbot.user_model')`.
 * Tento stub schválně NEMÁ relaci `aiSettings` ani nic dalšího nad rámec
 * tabulky `users`: dokazuje, že balíček po hostovi nic navíc nevyžaduje.
 *
 * Dědí z `Foundation\Auth\User` (tedy `Authenticatable`), protože chat routy
 * jedou pod middlewarem `auth` a testy potřebují `actingAs()` — to je
 * požadavek na KAŽDÝ host User model (bez Authenticatable by v Laravelu
 * nefungovalo přihlášení jako takové), ne požadavek balíčku navíc.
 *
 * `HasFactory` potřebují jen factory balíčku (ChatConversationFactory zakládá
 * vlastníka přes factory host modelu, když ho volající nedodá) — čistě
 * testovací pohodlí, runtime balíčku factory hosta nikdy nevolá.
 *
 * @use HasFactory<UserFactory>
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $table = 'users';

    protected $fillable = ['name', 'email'];

    /**
     * Factory se hledá podle konvence `Database\Factories\…`, která pro
     * testovací namespace balíčku neplatí — proto explicitně.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
