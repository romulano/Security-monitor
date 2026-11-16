// UI focada em leitura: a navegação principal é a barra de busca, então ela
// ganha atalho de teclado. Sem framework — o servidor entrega HTML pronto.

const search = document.querySelector('[data-search-input]');

document.addEventListener('keydown', (event) => {
    const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName ?? '');

    if (event.key === '/' && !typing) {
        event.preventDefault();
        search?.focus();
        search?.select();
        return;
    }

    if (event.key === 'Escape' && document.activeElement === search) {
        search?.blur();
    }
});

// Blocos "ver mais" das facetas e dos indicadores.
document.querySelectorAll('[data-toggle-target]').forEach((button) => {
    button.addEventListener('click', () => {
        const target = document.querySelector(button.dataset.toggleTarget);

        if (!target) return;

        if (target.hasAttribute('hidden')) {
            target.removeAttribute('hidden');
            button.textContent = button.dataset.labelLess;
        } else {
            target.setAttribute('hidden', '');
            button.textContent = button.dataset.labelMore;
        }
    });
});

// Grupos recolhíveis (filtros do feed): fechados no celular, sempre abertos no
// desktop, onde o botão nem recebe clique.
const desktop = window.matchMedia('(min-width: 64rem)');

document.querySelectorAll('[data-collapse]').forEach((button) => {
    const body = document.getElementById(button.getAttribute('aria-controls'));

    if (!body) return;

    const sync = () => button.setAttribute('aria-expanded', String(desktop.matches || !body.classList.contains('max-lg:hidden')));

    button.addEventListener('click', () => {
        body.classList.toggle('max-lg:hidden');
        sync();
    });
    desktop.addEventListener('change', sync);
    sync();
});

// Coletas e Fila: recarregam sozinhas para acompanhar o crawler e a IA ao vivo.
// A preferência fica no navegador, uma por página, para não virar mais uma configuração.
const autoRefresh = document.querySelector('[data-auto-refresh]');

if (autoRefresh) {
    const key = autoRefresh.dataset.autoRefreshKey ?? 'secmon.collections.autorefresh';
    let timer = null;

    const apply = () => {
        clearTimeout(timer);

        if (autoRefresh.checked) {
            timer = setTimeout(() => window.location.reload(), Number(autoRefresh.dataset.autoRefresh) * 1000);
        }
    };

    autoRefresh.checked = localStorage.getItem(key) === '1';
    autoRefresh.addEventListener('change', () => {
        localStorage.setItem(key, autoRefresh.checked ? '1' : '0');
        apply();
    });

    apply();
}

// Botão "Copiar" de valores que só aparecem uma vez (convite, senha inicial).
document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        const input = document.querySelector(button.dataset.copy);

        if (!input) return;

        input.select();

        try {
            await navigator.clipboard.writeText(input.value);
        } catch {
            document.execCommand('copy');
        }

        const label = button.textContent;
        button.textContent = 'Copiado';
        setTimeout(() => (button.textContent = label), 1500);
    });
});

// Campo de texto que pertence a uma opção (o "Outro domínio" do passo 1 dos testes): digitar
// nele marca a opção, para o domínio escrito não perder para o que estava marcado.
document.querySelectorAll('[data-check-on-input]').forEach((field) => {
    const option = document.getElementById(field.dataset.checkOnInput);

    if (!option) return;

    const check = () => {
        option.checked = true;
    };

    field.addEventListener('focus', check);
    field.addEventListener('input', check);
});

// Telegram: enquanto o link de conexão espera o /start, pergunta ao servidor
// e recarrega quando ele for usado (ou expirar), para mostrar o resultado.
const telegramWait = document.querySelector('[data-telegram-wait]');

if (telegramWait) {
    const until = Date.now() + 31 * 60 * 1000;

    const poll = async () => {
        try {
            const response = await fetch(telegramWait.dataset.telegramWait, { headers: { Accept: 'application/json' } });

            if (response.ok && !(await response.json()).waiting) {
                window.location.reload();

                return;
            }
        } catch {
            // rede instável: tenta de novo no próximo ciclo
        }

        if (Date.now() < until) setTimeout(poll, document.hidden ? 10000 : 3000);
    };

    setTimeout(poll, 3000);
}

// Entrevista de alertas. O servidor já entrega cada pergunta no estado certo;
// aqui só se acompanha o que o cliente muda antes de salvar.
const interview = document.querySelector('[data-interview]');

if (interview) {
    // Valores marcados de uma pergunta, pelo nome answers[chave] ou answers[chave][].
    const answerOf = (key) => {
        const fields = interview.querySelectorAll(`[name="answers[${key}]"], [name="answers[${key}][]"]`);

        return [...fields]
            .filter((f) => (f.type === 'checkbox' || f.type === 'radio' ? f.checked : f.type !== 'hidden' && f.value !== ''))
            .map((f) => f.value);
    };

    const refresh = () => {
        // Perguntas condicionais ("Quais frameworks?" só com "Usam framework? Sim").
        document.querySelectorAll('[data-show-if]').forEach((el) => {
            const rule = JSON.parse(el.dataset.showIf);
            el.hidden = !answerOf(rule.key).some((v) => rule.in.includes(v));
        });

        // Frameworks filtrados pelas linguagens marcadas (e distros pelo tipo de servidor).
        interview.querySelectorAll('[data-filter-by]').forEach((box) => {
            const map = JSON.parse(box.dataset.filterMap || '{}');
            const chosen = answerOf(box.dataset.filterBy).flatMap((v) => (Object.keys(map).length ? map[v] || [] : [v]));
            let shown = 0;

            box.querySelectorAll('[data-parent-group]').forEach((group) => {
                group.hidden = !chosen.includes(group.dataset.parentGroup);
                shown += group.hidden ? 0 : 1;
            });

            const empty = box.querySelector('[data-filter-empty]');
            if (empty) empty.hidden = shown > 0;
        });
    };

    interview.addEventListener('change', refresh);

    // Campo de termos: Enter ou vírgula vira um chip com input escondido.
    interview.querySelectorAll('[data-tags]').forEach((box) => {
        const input = box.querySelector('[data-tags-input]');

        const add = () => {
            const value = input.value.replace(/,$/, '').trim();
            input.value = '';

            if (!value) return;

            const exists = [...box.querySelectorAll('[data-tag] input')].some((i) => i.value.toLowerCase() === value.toLowerCase());
            if (exists) return;

            const chip = document.createElement('span');
            chip.dataset.tag = '';
            chip.className = 'inline-flex items-center gap-1 rounded-md bg-ink-800 py-0.5 pr-1 pl-2.5 text-sm text-white';
            chip.append(document.createTextNode(value));

            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = box.dataset.name;
            hidden.value = value;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.dataset.tagRemove = '';
            remove.setAttribute('aria-label', `Remover ${value}`);
            remove.className = 'grid size-7 place-items-center rounded text-ink-400 hover:text-white';
            remove.textContent = '×';

            chip.append(hidden, remove);
            box.insertBefore(chip, input);
        };

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                add();
            } else if (event.key === 'Backspace' && input.value === '') {
                box.querySelector('[data-tag]:last-of-type')?.remove();
            }
        });

        box.addEventListener('click', (event) => {
            const button = event.target.closest('[data-tag-remove]');
            if (button) button.closest('[data-tag]').remove();
        });
    });
}

// Painéis flutuantes dos alertas ("Motivo" e "Avaliar"): popover nativo, que
// fecha com Esc ou clique fora. Sem este trecho ele abre no centro da tela; com
// ele, aparece junto ao botão que o abriu, abaixo dele (ou acima, se não couber)
// e sempre dentro da tela.
const floats = document.querySelectorAll('[data-float]');

if (floats.length) {
    const openers = new Map();

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[popovertarget]');

        if (button && button.getAttribute('popovertargetaction') !== 'hide') {
            openers.set(button.getAttribute('popovertarget'), button);
        }
    }, true);

    const place = (panel) => {
        const button = openers.get(panel.id) ?? document.querySelector(`[popovertarget="${panel.id}"]`);

        if (!button) return;

        const box = button.getBoundingClientRect();
        const margin = 12;
        const left = Math.max(margin, Math.min(box.right - panel.offsetWidth, window.innerWidth - panel.offsetWidth - margin));
        const below = box.bottom + 6;
        const fitsBelow = below + panel.offsetHeight <= window.innerHeight - margin;
        const top = fitsBelow ? below : Math.max(margin, box.top - 6 - panel.offsetHeight);

        Object.assign(panel.style, { margin: '0', inset: 'auto', left: `${left}px`, top: `${top}px` });
    };

    floats.forEach((panel) => panel.addEventListener('toggle', (event) => {
        if (event.newState !== 'open') return;

        place(panel);

        // "Relevante" ou "Não é relevante" ("É golpe" ou "Não é ameaça" no Radar): a opção do botão clicado já vem marcada.
        const vote = openers.get(panel.id)?.dataset.vote;
        const choice = vote && panel.querySelector(`input[name="feedback"][value="${vote}"], input[name="acao"][value="${vote}"]`);

        if (choice) choice.checked = true;

        panel.querySelector('textarea')?.focus();
    }));

    const replace = () => document.querySelectorAll('[data-float]:popover-open').forEach(place);
    window.addEventListener('scroll', replace, { passive: true });
    window.addEventListener('resize', replace);
}

// Contador do motivo da avaliação ("42/280").
document.querySelectorAll('textarea[data-counter]').forEach((field) => {
    const label = document.querySelector(`[data-counter-for="${field.id}"]`);
    const sync = () => {
        if (label) label.textContent = `${field.value.length}/${field.maxLength}`;
    };

    field.addEventListener('input', sync);
    sync();
});

// Interruptor que mostra/esconde as opções que dependem dele.
document.querySelectorAll('input[type=checkbox][data-reveals]').forEach((toggle) => {
    const target = document.querySelector(toggle.dataset.reveals);

    if (target) {
        toggle.addEventListener('change', () => (target.hidden = !toggle.checked));
    }
});

// Caixa de diálogo padrão (components/dialog.blade.php). Confirmações e avisos
// passam sempre por aqui, nunca por alert()/confirm() do navegador. Cada chamada
// devolve uma Promise: confirm() resolve true/false; alert(), quando fecha.
const dialog = (() => {
    const root = document.querySelector('[data-dialog]');

    if (!root) return null;

    const heading = root.querySelector('[data-dialog-title]');
    const text = root.querySelector('[data-dialog-message]');
    const ok = root.querySelector('[data-dialog-ok]');
    const cancel = root.querySelector('[data-dialog-cancel]');
    const tones = {
        primary: 'bg-accent text-ink-950 hover:brightness-110',
        danger: 'border border-red-900 bg-red-950/60 text-red-200 hover:bg-red-900/50',
    };
    let settle = null;
    let opener = null;

    const close = (result) => {
        if (!settle) return;

        root.hidden = true;
        document.documentElement.classList.remove('overflow-hidden');
        settle(result);
        settle = null;
        opener?.focus();
    };

    const open = ({ title, message = '', okLabel = 'OK', cancelLabel = 'Cancelar', tone = 'primary', withCancel = true, focus = null }) => {
        close(false);

        heading.textContent = title;
        text.textContent = message;
        text.hidden = !message;
        ok.textContent = okLabel;
        ok.className = `min-h-11 rounded-lg px-4 text-sm font-semibold ${tones[tone] ?? tones.primary}`;
        cancel.textContent = cancelLabel;
        cancel.hidden = !withCancel;

        opener = document.activeElement;
        root.hidden = false;
        document.documentElement.classList.add('overflow-hidden');
        // Em ação destrutiva o foco começa no "Cancelar": um Enter distraído não apaga nada. Parar um teste é a
        // exceção (focus: 'ok'): ali o Enter confirma e o Esc cancela, porque parar nunca faz mal.
        ((focus ?? (tone === 'danger' && withCancel ? 'cancel' : 'ok')) === 'cancel' ? cancel : ok).focus();

        return new Promise((resolve) => (settle = resolve));
    };

    ok.addEventListener('click', () => close(true));
    cancel.addEventListener('click', () => close(false));
    root.querySelector('[data-dialog-backdrop]').addEventListener('click', () => close(false));

    // Com o diálogo aberto, o teclado fica preso nele (Esc cancela, Tab circula
    // entre os botões) e os atalhos da página, como o "/", não disparam por trás.
    document.addEventListener('keydown', (event) => {
        if (root.hidden) return;

        event.stopPropagation();

        if (event.key === 'Escape') {
            event.preventDefault();
            close(false);
        } else if (event.key === 'Tab') {
            event.preventDefault();
            const buttons = [cancel, ok].filter((b) => !b.hidden);
            const index = buttons.indexOf(document.activeElement);
            buttons[(index + (event.shiftKey ? -1 : 1) + buttons.length) % buttons.length].focus();
        }
    }, { capture: true });

    const options = (input) => (typeof input === 'string' ? { title: input } : input);

    return {
        confirm: (input) => open({ okLabel: 'Confirmar', ...options(input) }),
        alert: (input) => open({ ...options(input), withCancel: false }),
    };
})();

window.dialog = dialog;

// Formulários com data-confirm="Pergunta? Explicação." pedem confirmação na caixa
// padrão: até o primeiro "?" vira título, o resto vira texto, e o botão repete o
// rótulo do submit ("Excluir", "Desconectar"…). DELETE ganha o tom de perigo; o
// formulário pode pedir outro tom (data-confirm-tone), o foco no botão de
// confirmar (data-confirm-focus="ok") e outro rótulo para fechar sem fazer nada
// (data-confirm-cancel="Manter o teste", quando "Cancelar" é a própria ação). O
// ouvinte fica no documento: os pedaços que as páginas trocam sozinhas (o teste ao
// vivo e a faixa) continuam pedindo. Num formulário com vários botões (uma senha só
// para todas as ações), a pergunta pode ficar no botão: data-confirm no <button>.
document.addEventListener('submit', async (event) => {
    const form = event.target;
    const submitter = event.submitter;
    // A pergunta que depende da escolha: a opção marcada de um select[data-confirm-choice] traz a dela
    // (data-confirm) e o rótulo do botão (data-confirm-ok), lidos na hora de enviar. O botão com a
    // pergunta dele (o "Exigir" no mesmo formulário do "Redefinir") não usa a da escolha.
    const choice = submitter?.dataset.confirm ? {} : (form.querySelector?.('select[data-confirm-choice]')?.selectedOptions[0]?.dataset ?? {});
    const ask = submitter?.dataset.confirm ?? choice.confirm ?? form.dataset?.confirm;

    if (!(form instanceof HTMLFormElement) || !ask || 'confirmed' in form.dataset || !dialog) return;

    event.preventDefault();

    const button = submitter ?? form.querySelector('button[type=submit], button:not([type])');
    const deletes = (submitter?.name === '_method' && submitter.value === 'DELETE') || form.querySelector('input[name=_method][value=DELETE]');
    const [, title, message] = ask.match(/^(.*?\?)\s+(.+)$/s) ?? [null, ask, ''];
    const confirmed = await dialog.confirm({
        title,
        message,
        okLabel: choice.confirmOk ?? form.dataset.confirmOk ?? (button?.textContent.trim() || 'Confirmar'),
        cancelLabel: form.dataset.confirmCancel,
        // O tom também pode vir da opção escolhida (trocar o setor para um em que o monitoramento fica mudo).
        tone: choice.confirmTone ?? form.dataset.confirmTone ?? (deletes ? 'danger' : 'primary'),
        focus: form.dataset.confirmFocus ?? null,
    });

    if (confirmed && form.isConnected) {
        form.dataset.confirmed = '';
        form.requestSubmit(submitter?.form === form ? submitter : undefined);
        delete form.dataset.confirmed;
    }
});

// Formulário com data-once (o passo 1 que cria um monitoramento): o segundo clique, ou o Enter repetido, não envia de
// novo, e os botões dele ficam desabilitados até a página trocar. Ao voltar pelo histórico, valem de novo.
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !('once' in form.dataset) || event.defaultPrevented) return;

    if ('sent' in form.dataset) {
        event.preventDefault();

        return;
    }

    form.dataset.sent = '';
    const buttons = [...form.querySelectorAll('button'), ...(form.id ? document.querySelectorAll(`button[form="${CSS.escape(form.id)}"]`) : [])];
    // Depois do envio sair: desabilitar antes tiraria o botão (e o valor dele) do envio.
    setTimeout(() => buttons.forEach((button) => button.setAttribute('disabled', '')));
});
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    document.querySelectorAll('form[data-once][data-sent]').forEach((form) => {
        delete form.dataset.sent;
        document.querySelectorAll(`#${CSS.escape(form.id)} button, button[form="${CSS.escape(form.id)}"]`).forEach((button) => button.removeAttribute('disabled'));
    });
});

// Testes de segurança: a página da execução (data-run-live) e a faixa do teste
// (data-pentest-banner) se atualizam sozinhas. O servidor manda o pedaço já
// montado; com o foco ou a caixa de confirmação nele, a troca espera a próxima
// volta. Se a consulta falha, fica o último estado, com o aviso de quanto tempo
// está sem atualização, e o Parar continua valendo (Estados-gerais, situação 9).
// O contador visível muda a cada segundo e fica fora do leitor de tela; ele ouve
// uma vez quando a consulta passa a falhar, uma vez quando volta e uma vez quando a
// sessão termina (401, 403 ou 419: aí o Parar também não passaria).
//
// Com a aba escondida há 10 minutos, a consulta dorme até a aba voltar: assim ela
// não segura uma sessão esquecida (item 6 do Blue Team; o servidor também não
// renova a sessão com a consulta). Ao voltar, consulta na hora.
const dialogOpen = () => {
    const root = document.querySelector('[data-dialog]');

    return root ? !root.hidden : false;
};

const ENDED = 'A sua sessão terminou. Entre de novo para continuar; o teste segue até você parar ou até o fim da janela.';

// "Sem atualização há 40 s." e, depois do primeiro minuto, "há 2 min".
const staleText = (seconds) => `Sem atualização há ${seconds < 60 ? `${seconds} s` : `${Math.round(seconds / 60)} min`}. O botão Parar continua valendo.`;

const HIDDEN_LIMIT = 10 * 60 * 1000;

const livePoll = (root, url, apply, show, announce, { visible = false, onData = null } = {}) => {
    const every = Math.max(3, Number(root.dataset.poll) || 10) * 1000;
    let last = Date.now();
    let failures = 0;
    let previous = null;
    let state = 'ok';
    let clock = null;
    let hiddenSince = document.hidden ? Date.now() : null;
    let sleeping = false;

    const render = () => {
        if (sleeping) return;

        const seconds = Math.round((Date.now() - last) / 1000);

        if (state !== 'ended') {
            const next = failures > 1 || seconds > (every * 3) / 1000 ? 'stale' : 'ok';

            if (next !== state) {
                announce(next === 'stale' ? 'Sem atualização. O botão Parar continua valendo.' : 'Atualizado de novo.');
                state = next;
            }
        }

        show(state, seconds);
    };

    const tick = async () => {
        try {
            // A aba visível é a única que conta como "vista" (B1); o X-Requested-With tira a consulta da "página
            // anterior" do Laravel, para o voltar do Parar nunca cair no JSON (B2).
            const target = visible && document.visibilityState === 'visible' ? `${url}${url.includes('?') ? '&' : '?'}visivel=1` : url;
            const response = await fetch(target, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });

            if ([401, 403, 419].includes(response.status)) {
                state = 'ended';
                announce(ENDED);
                clearInterval(clock);
                show(state, 0);

                return;
            }

            if (!response.ok) throw new Error(String(response.status));

            const data = await response.json();
            failures = 0;
            last = Date.now();
            root.dataset.live = data.live ? '1' : '0';
            onData?.(data);

            if (data.html !== previous && !root.contains(document.activeElement) && !dialogOpen()) {
                previous = data.html;
                apply(data.html);
            }
        } catch {
            failures++;
        }

        render();

        if (root.dataset.live !== '1') return;

        if (hiddenSince !== null && Date.now() - hiddenSince >= HIDDEN_LIMIT) {
            sleeping = true;

            return;
        }

        setTimeout(tick, document.hidden ? every * 3 : every);
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            hiddenSince ??= Date.now();

            return;
        }

        hiddenSince = null;

        if (sleeping && state !== 'ended') {
            sleeping = false;
            tick();
        }
    });

    if (root.dataset.live === '1') {
        setTimeout(tick, every);
        clock = setInterval(render, 1000);
    }
};

// O aviso da sessão que terminou, com o link para entrar de novo (o texto vai por textContent). O contador saía do
// leitor de tela; o aviso parado, com o link, volta para ele.
const endedNote = (element, login) => {
    element.removeAttribute('aria-hidden');
    element.textContent = `${ENDED} `;

    if (login) {
        const link = document.createElement('a');
        link.href = login;
        link.className = 'underline';
        link.textContent = 'Entrar';
        element.append(link);
    }
};

const runLive = document.querySelector('[data-run-live]');

if (runLive) {
    const announcer = document.querySelector('[data-run-announce]');

    // O aviso fica logo abaixo de "Começou em…", dentro do pedaço trocado: é buscado de novo a cada volta.
    livePoll(runLive, runLive.dataset.runLive, (html) => (runLive.innerHTML = html), (state, seconds) => {
        const note = runLive.querySelector('[data-run-age]');

        if (!note) return;

        note.classList.toggle('text-amber-200', state !== 'ok');
        note.classList.toggle('text-ink-400', state === 'ok');

        if (state === 'ended') {
            endedNote(note, runLive.dataset.login);
        } else {
            note.textContent = state === 'ok' ? `Atualizado há ${seconds} s.` : staleText(seconds);
        }
    }, (message) => announcer && (announcer.textContent = message), {
        visible: true,
        // O estado novo, uma vez por mudança (rodando → parando → parado), para quem usa leitor de tela.
        onData: (data) => {
            if (data.pill && data.pill !== runLive.dataset.pill) {
                runLive.dataset.pill = data.pill;

                if (announcer) announcer.textContent = `Estado: ${data.pill}`;
            }
        },
    });
}

const banner = document.querySelector('[data-pentest-banner]');

if (banner) {
    const announcer = document.querySelector('[data-banner-announce]');

    livePoll(banner, banner.dataset.pentestBanner, (html) => (banner.innerHTML = html), (state, seconds) => {
        const note = banner.querySelector('[data-banner-stale]');

        if (!note) return;

        note.hidden = state === 'ok';

        if (state === 'ended') {
            endedNote(note, banner.dataset.login);
        } else if (state === 'stale') {
            note.textContent = staleText(seconds);
        }
    }, (message) => announcer && (announcer.textContent = message));
}
