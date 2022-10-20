<?php

namespace App\Models;

use App\Classes\Pterodactyl\PterodactylClient;
use App\Exceptions\PterodactylRequestException;
use App\Models\Pterodactyl\Egg;
use App\Models\Pterodactyl\Node;
use App\Settings\PterodactylSettings;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\Client\Response;

class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'pterodactyl_id',
        'user_id',
        'identifier',
        'name',
        'description',
        'status',
        'suspended',
        'memory',
        'cpu',
        'swap',
        'disk',
        'io',
        'databases',
        'backups',
        'allocations',
        'threads',
        'oom_disabled',
        'node_id',
        'allocation_id',
        'nest_id',
        'egg_id',
        'price',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'pterodactyl_id' => 'int',
        'suspended' => 'bool',
        'price' => 'float',
        'memory' => 'int',
        'cpu' => 'int',
        'swap' => 'int',
        'disk' => 'int',
        'io' => 'int',
        'databases' => 'int',
        'backups' => 'int',
        'allocations_id' => 'int',
        'nest_id' => 'int',
        'egg_id' => 'int',
        'node_id' => 'int',
    ];

    protected $appends = [
        'pterodactyl_client_url',
        'pterodactyl_admin_url',
        'price_per_hour',
        'price_per_day',
    ];

    protected static function boot()
    {
        parent::boot();

        //delete server on pterodactyl
        static::deleting(function (Server $server) {
            /** @var PterodactylClient $client */
            $client = app(PterodactylClient::class);

            try {
                //delete server on pterodactyl
                $client->deleteServer($server->pterodactyl_id);
            } catch (PterodactylRequestException $exception) {
                //throw exception if it's not a 404 error
                if ($exception->getCode() !== 404) throw $exception;
            }
        });
    }

    /**
     * Create server object using Pterodactyl response
     *
     * @param Response $response
     * @param User $user
     * @param float $price
     * @return Server
     */
    public static function createFromPterodactylResponse(Response $response, User $user, float $price): Server
    {
        $data = $response->body();
        $data = json_decode($data, true);

        return self::create([
            'pterodactyl_id' => $data['attributes']['id'],
            'user_id' => $user->id,
            'identifier' => $data['attributes']['identifier'],
            'name' => $data['attributes']['name'],
            'description' => $data['attributes']['description'],
            'status' => $data['attributes']['status'],
            'suspended' => $data['attributes']['suspended'],
            'memory' => $data['attributes']['limits']['memory'],
            'cpu' => $data['attributes']['limits']['cpu'],
            'swap' => $data['attributes']['limits']['swap'],
            'disk' => $data['attributes']['limits']['disk'],
            'io' => $data['attributes']['limits']['io'],
            'threads' => $data['attributes']['limits']['threads'],
            'oom_disabled' => $data['attributes']['limits']['oom_disabled'],
            'databases' => $data['attributes']['feature_limits']['databases'],
            'backups' => $data['attributes']['feature_limits']['backups'],
            'allocations' => $data['attributes']['feature_limits']['allocations'],
            'node_id' => $data['attributes']['node'],
            'allocation_id' => $data['attributes']['allocation'],
            'nest_id' => $data['attributes']['nest'],
            'egg_id' => $data['attributes']['egg'],
            'price' => $price
        ]);
    }

    /**
     * Charge the user for the server
     *
     * @return bool true if the user was charged, false if the user doesn't have enough money
     */
    public function chargeCredits(): bool
    {
        $hourlyPrice = $this->price / 30 / 24;

        /** @var User $user */
        $user = $this->user;

        //check if the user has enough credits to pay for the server
        if ($user->credits >= $hourlyPrice) {
            $user->decrement('credits', $hourlyPrice);
            return true;
        }

        
        //TODO change to $user->suspendServers() and suspend all servers
        //TODO change server suspend to only suspend and not notify as wel.
        //suspend the server
        $this->suspend();

        return false;
    }

    /**
     * Suspends the server
     *
     * @param bool $notify Notify the user that the server was suspended
     * @return Server
     */
    public function suspend(bool $notify = true): static
    {
        $this->suspended = true;
        $this->save();

        //notify the user
        if ($notify) {
            static $notifiedUsers = [];

            //don't notify the user if they were already notified
            if (!in_array($this->user_id, $notifiedUsers)) {
                /** @var User $user */
                $user = $this->user;

                //NOTE this notification is to inform the user that ALL servers were suspended
                NotificationTemplate::sendNotification($user, 'servers-suspended', [
                    'user' => $user,
                ]);

                $notifiedUsers[] = $this->user_id;
            }
        }

        return $this;
    }

    /**
     * Unsuspends the server
     *
     * @return Server
     */
    public function unsuspend(): static
    {
        $this->suspended = false;
        $this->save();
        return $this;
    }

    /**
     * Generate the client url to Pterodactyl
     *
     * @return Attribute
     */
    public function pterodactylClientUrl(): Attribute
    {
        return Attribute::get(function () {
            /** @var PterodactylSettings $settings */
            $settings = app(PterodactylSettings::class);

            return $settings->getUrl() . 'server/' . $this->identifier;
        });
    }

    /**
     * Generate the admin url to Pterodactyl
     *
     * @return Attribute
     */
    public function pterodactylAdminUrl(): Attribute
    {
        return Attribute::get(function () {
            /** @var PterodactylSettings $settings */
            $settings = app(PterodactylSettings::class);

            return $settings->getUrl() . '/admin/servers/view/' . $this->pterodactyl_id;
        });
    }

    /**
     * Get the price per hour
     *
     * @return Attribute
     */
    public function pricePerHour(): Attribute
    {
        return Attribute::get(fn() => number_format($this->price / 30 / 24, 2, '.', ''));
    }

    /**
     * Get the price per day
     *
     * @return Attribute
     */
    public function pricePerDay(): Attribute
    {
        return Attribute::get(fn() => number_format($this->price / 30, 2, '.', ''));
    }

    /**
     * Get user object
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get egg object
     *
     * @return BelongsTo
     */
    public function egg(): BelongsTo
    {
        return $this->belongsTo(Egg::class);
    }

    /**
     * Get node object
     *
     * @return BelongsTo
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class,);
    }
}
