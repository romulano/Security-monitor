<?php

namespace App\Services\Admin;

use App\Exceptions\ActionRefused;
use App\Models\InterviewQuestion;
use App\Models\TechCatalog;
use Illuminate\Support\Facades\DB;

/** Admin → Alertas → Perguntas: o roteiro da entrevista. Mudanças valem para quem abrir a entrevista depois. */
class InterviewQuestionManager
{
    /**
     * @param  ?int  $editingId  a pergunta aberta no formulário de edição
     * @param  bool  $creating  formulário de nova pergunta aberto
     */
    public function page(?int $editingId, bool $creating): array
    {
        return [
            'questions' => InterviewQuestion::query()->ordered()->get(),
            'editing' => $editingId ? InterviewQuestion::query()->find($editingId) : null,
            'creating' => $creating,
            'categories' => TechCatalog::CATEGORIES,
        ];
    }

    /** A pergunta nova vai para o fim do seu passo; nunca é de sistema. */
    public function create(array $fields): InterviewQuestion
    {
        return InterviewQuestion::query()->create($fields + [
            'is_system' => false,
            'position' => (int) InterviewQuestion::query()->where('step', $fields['step'])->max('position') + 1,
        ]);
    }

    public function update(InterviewQuestion $question, array $fields): void
    {
        $question->update($fields);
    }

    /** Troca de lugar com a vizinha do mesmo passo (`$direction`: -1 sobe, 1 desce); na ponta, nada muda. */
    public function move(InterviewQuestion $question, int $direction): void
    {
        $siblings = InterviewQuestion::query()->where('step', $question->step)->orderBy('position')->orderBy('id')->get()->values();
        $index = $siblings->search(fn ($q) => $q->id === $question->id);
        $target = $siblings->get($index + $direction);

        if ($target) {
            DB::transaction(function () use ($siblings, $index, $direction) {
                $ordered = $siblings->all();
                [$ordered[$index], $ordered[$index + $direction]] = [$ordered[$index + $direction], $ordered[$index]];

                foreach ($ordered as $position => $q) {
                    $q->update(['position' => $position + 1]);
                }
            });
        }
    }

    public function delete(InterviewQuestion $question): void
    {
        if ($question->is_system) {
            throw new ActionRefused('Perguntas de sistema alimentam o perfil e não podem ser apagadas; desative se não quiser perguntar.');
        }

        $question->delete();
    }
}
