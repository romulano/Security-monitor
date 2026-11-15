<?php

namespace App\Services\Admin;

use App\Models\AlertProfile;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\TechCatalog;
use App\Models\User;
use App\Services\Alerts\InterviewService;
use App\Services\Alerts\Notifier;
use App\Services\Alerts\ProfilePreview;
use Illuminate\Support\Collection;

/** Admin → Alertas → Clientes: a lista, o detalhe e o que o admin faz no perfil de um cliente. */
class AlertClientManager
{
    public function __construct(
        private InterviewService $interview,
        private ProfilePreview $preview,
        private Notifier $notifier,
    ) {}

    /** @param  array{q?: ?string, perfil?: ?string, canal?: ?string}  $filters */
    public function listPage(array $filters): array
    {
        $users = User::query()
            ->where('is_admin', false)
            ->with(['alertProfile' => fn ($q) => $q->withCount([
                'techs',
                'matches as alerts_30d' => fn ($m) => $m->relevant()->where('matched_at', '>=', now()->subDays(30)),
                'matches as irrelevant_30d' => fn ($m) => $m->relevant()->where('feedback', 'irrelevant')->where('matched_at', '>=', now()->subDays(30)),
            ]), 'notificationChannels'])
            ->withCount('alertProfiles')
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$s}%")
                ->orWhere('email', 'ilike', "%{$s}%")
                ->orWhereHas('alertProfiles', fn ($p) => $p->where('company_name', 'ilike', "%{$s}%")->orWhere('name', 'ilike', "%{$s}%"))))
            ->when($filters['perfil'] ?? null, fn ($q, $status) => match ($status) {
                'none' => $q->whereDoesntHave('alertProfiles'),
                'draft' => $q->whereHas('alertProfiles', fn ($p) => $p->whereNull('completed_at')),
                default => $q->whereHas('alertProfiles', fn ($p) => $p->where('status', $status)->whereNotNull('completed_at')),
            })
            ->when($filters['canal'] ?? null, fn ($q, $canal) => match ($canal) {
                'none' => $q->whereDoesntHave('notificationChannels'),
                'ok' => $q->whereHas('notificationChannels', fn ($c) => $c->where('status', 'active')),
                'broken' => $q->whereHas('notificationChannels', fn ($c) => $c->where('status', 'broken')),
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $lastSent = NotificationDelivery::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->where('status', 'sent')
            ->selectRaw('user_id, max(sent_at) as last')
            ->groupBy('user_id')
            ->pluck('last', 'user_id');

        return [
            'users' => $users,
            'lastSent' => $lastSent,
            'filters' => $filters,
            'counts' => [
                'clients' => User::query()->where('is_admin', false)->count(),
                'active' => AlertProfile::query()->where('status', 'active')->whereNotNull('completed_at')->count(),
                'drafts' => AlertProfile::query()->whereNull('completed_at')->count(),
                'paused' => AlertProfile::query()->where('status', 'paused')->count(),
            ],
        ];
    }

    /** `$profile`: o alerta escolhido (ou o primeiro); `$preview`: roda a prévia de 30 dias. */
    public function detailPage(User $user, ?AlertProfile $profile, bool $preview): array
    {
        $profile = $profile?->load(['techs', 'terms', 'domains.findings']);
        $values = $profile ? $this->interview->values($profile) : [];

        return [
            'user' => $user,
            'profile' => $profile,
            'profiles' => $user->alertProfiles()->oldest('id')->get(),
            'channels' => $user->notificationChannels,
            'answers' => $profile ? $this->interview->questions()
                ->filter(fn ($q) => $this->interview->visible($q, $values))
                ->groupBy('step')
                ->map(fn ($qs) => $qs->map(fn ($q) => ['prompt' => $q->prompt, 'answer' => $this->interview->display($q, $values[$q->key] ?? null, $values[$q->key.InterviewService::OTHER] ?? [])])
                    ->reject(fn ($a) => $a['answer'] === '—')) : collect(),
            'matches' => $profile ? $profile->matches()->with('case:id,uuid,title,title_pt,criticity')->latest('matched_at')->latest('id')->paginate(15) : null,
            'deliveries' => NotificationDelivery::query()->where('user_id', $user->id)->with('channel')->latest()->latest('id')->limit(10)->get(),
            'preview' => $profile && $preview ? $this->preview->run($profile) : null,
            'catalog' => TechCatalog::query()->active()->orderBy('name')->get(['id', 'name', 'category']),
        ];
    }

    /** Pausa ou retoma o alerta; devolve se ficou pausado. */
    public function togglePause(AlertProfile $profile): bool
    {
        $paused = ! $profile->isPaused();
        $profile->forceFill(['status' => $paused ? 'paused' : 'active', 'paused_at' => $paused ? now() : null])->save();
        $this->interview->recompile($profile);

        return $paused;
    }

    /** @param  Collection<int, NotificationChannel>  $channels */
    public function sendTest(Collection $channels): void
    {
        foreach ($channels as $channel) {
            $this->notifier->test($channel);
        }
    }

    /** O admin pode acrescentar ou tirar tecnologias de um perfil (origem "admin"). */
    public function updateTech(AlertProfile $profile, int $techId, bool $add): void
    {
        if ($add) {
            $profile->techs()->syncWithoutDetaching([$techId => ['source' => 'admin', 'created_at' => now()]]);
        } else {
            $profile->techs()->detach($techId);
        }

        $this->interview->recompile($profile->refresh());
    }
}
