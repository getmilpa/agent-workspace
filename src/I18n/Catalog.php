<?php

/**
 * This file is part of milpa/agent-workspace — the agent's workspace inside a Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\I18n;

/**
 * The Desktop's human-facing copy, by key, in English (default) and Spanish.
 *
 * The shell asks for a key and the catalog answers in the declared locale (`workspace.locale`), falling
 * back to English, and to the key itself when nobody wrote it. The keys the door added (greenhouse
 * decisions/0209) — the topbar chips, the gate's refusal, the guard's notices — live here; the shell's
 * older copy migrates key by key as it is touched. The same shape as milpa/admin's catalog, kept apart
 * on purpose: the Desktop names no dependency on the admin.
 */
final class Catalog
{
    public const DEFAULT_LOCALE = 'en';

    /** @var array<string, array<string, string>> */
    private const MESSAGES = [
        'en' => [
            'chip.gate' => 'gate: %s',
            'gate.kind.loopback' => 'loopback',
            'gate.kind.custom' => 'custom',
            'gate.kind.passkey' => 'passkey',
            'gate.kind.open' => 'open',
            'gate.kind.fallback' => 'fallback',
            'gate.loopback.title' => 'Loopback only',
            'gate.loopback' => 'This workspace answers only to loopback by default. Declare workspace.middleware in config/app.php to put it behind your own gate.',
            'topbar.signed_in' => 'signed in as %s',
            'settings.saved' => 'Saved',
            'settings.save_failed' => 'Not saved (HTTP %s)',
            'guard.forbidden' => 'Not allowed here',
            'guard.forbidden.reason' => 'Not allowed here (%s)',
            'guard.failed' => 'The request failed (HTTP %s)',
            'guard.unreachable' => 'The app could not be reached',
            'enroll.none' => 'No passkey door in this app',
            'agent.title' => 'Agent',
            // ── THE SCREENS, NAMED ONCE ──────────────────────────────────────────────────────
            // Both doors read these: the Desktop's own sidebar and the panel, which titles the same
            // screens as sections of its own. One source for what a screen is CALLED, so the two can
            // never disagree — and they used to be English literals in `Sidebar::NAV`, which gave a
            // person who chose Spanish a Spanish panel with an English navigation
            // (greenhouse decisions/0139, caught measuring decisions/0268).
            'nav.sessions' => 'Sessions',
            'nav.decisions' => 'Decisions',
            'nav.skills' => 'Skills',
            'nav.subagents' => 'Subagents',
            'tab.chat' => 'Conversation',
            'tab.decisions' => 'Decisions',
            'tab.work' => 'Work',
            'tab.activity' => 'Activity',
            'tab.context' => 'Context',
            'nav.preview' => 'Preview',
            'nav.settings' => 'Settings',
            'agent.open' => 'Open the Desktop',
            'agent.signin' => 'Sign in to open the Agent',
            'agent.signin.action' => 'Sign in',
            // A surface of the Agent region that could not be painted (greenhouse decisions/0211, slice 3):
            // it says which one, inside its own node, and the rest of the region stands.
            'agent.surface.failed' => 'This part of the Agent could not be shown (%s).',
            'strip.session' => 'Session',
            'strip.none' => 'No session open',
            'strip.pick' => 'Pick a session',
            'strip.new' => 'New session',
            // The Settings screen (greenhouse decisions/0211, phase B7). The autonomy badges («ask»,
            // «acknowledge», «auto») are the mode's own VALUES, not copy, and stay as they are.
            'settings.model.title' => 'Model and provider',
            'settings.model.provider' => 'Provider',
            'settings.model.endpoint' => 'Endpoint',
            'settings.model.endpoint_hint' => 'The endpoint receives context. It does not execute operations.',
            'settings.model.model' => 'Model',
            'settings.model.model_hint' => 'What this app declared. «Find models» asks the endpoint what it actually serves — the only control here that goes out on the wire.',
            'settings.model.model.none' => 'None declared',
            'settings.model.model.find' => 'Find models',
            'settings.model.model.found' => '%s model(s) served',
            'settings.model.model.unreachable' => 'The endpoint did not answer',
            'settings.model.model.saved' => 'Model saved',
            'settings.model.model.refused' => 'The model was not saved: %s',
            'settings.write.no_policy' => 'This app cannot accept these yet: nothing here can say who may reconfigure the agent. Identity is what a write like this is gated by, so the fields are not offered rather than failing when you press save.',
            'settings.write.no_policy_command' => 'php bin/coa capabilities:enable milpa/auth --sign',
            'settings.write.no_door' => 'Identity is installed and no door is mounted: without a relying party the passkey ceremony has no routes, so nobody can be signed in and no write can be authorized. Declare it and the fields appear.',
            'settings.write.no_door_command' => "passkey.rpId in config/app.php",
            'settings.model.endpoint.save' => 'Save endpoint',
            'settings.model.endpoint.saved' => 'Endpoint saved',
            'settings.model.endpoint.refused' => 'The endpoint was not saved: %s',
            'settings.model.key' => 'API key',
            'settings.model.key.hint' => 'Written where the code reads it and git never does, by a signed operation. Nothing here can read it back.',
            'settings.model.key.placeholder' => 'Paste the provider key',
            'settings.model.key.replace' => 'Paste a new key to replace it',
            'settings.model.key.held' => 'There is a key. It is never shown — replace it by pasting a new one.',
            'settings.model.key.save' => 'Save key',
            'settings.model.key.saved' => 'Key saved',
            'settings.model.key.refused' => 'The key was not saved: %s',
            'settings.autonomy.title' => 'Default autonomy',
            'settings.autonomy.ask' => 'Ask before changing',
            'settings.autonomy.ask_hint' => 'Pauses mutations without a standing permission.',
            'settings.autonomy.acknowledge' => 'Compatibility',
            'settings.autonomy.acknowledge_hint' => 'Today decides like auto: no observable prior notice.',
            'settings.autonomy.auto' => 'Continue automatically',
            'settings.autonomy.auto_hint' => 'Signatures and incomplete intent still stop.',
            'settings.autonomy.note' => 'The three values are not yet three behaviorally distinct levels.',
            'settings.storage.title' => 'Context and storage',
            'settings.storage.compact_note' => 'Compaction reduces what the model sees, never the session record.',
            'settings.storage.folder' => 'Sessions folder',
            'settings.discard' => 'Discard changes',
            'settings.save' => 'Save settings',
            // The entry overlay (phase B5). «Milpa Desktop» is the product's name, not copy: it is not a key.
            'auth.kicker' => 'local workspace',
            'auth.lede' => "Open a Milpa app to start, understand and resume an agent's work. The session is the unit; nothing runs on open.",
            'auth.app' => 'Milpa app',
            'auth.app_hint' => 'Reads %s. One app at a time.',
            'auth.identity' => 'Decision identity',
            'auth.identity.system' => 'System user',
            'auth.identity.system_hint' => 'Not verified. Call signatures are asked for separately.',
            'auth.identity.verified' => 'Signature-verified principal',
            'auth.identity.verified_hint' => 'Requires an external mechanism.',
            'auth.provider' => 'Model provider',
            // WHAT A SURFACE SAYS WHEN NOBODY DECLARED A MODEL. Not a default name: this package
            // printed `qwen3.8-27b` in five places and `http://llama.local:11438` in two, so every
            // one of them asserted a model it had never asked — on a host that stopped resolving
            // when that machine moved (greenhouse decisions/0266). «I do not know» is the only true
            // answer a surface has when the authority has nothing to give it.
            'model.undeclared' => 'no model declared',
            'model.unreachable' => '%s · not answering',
            'model.not_served' => '%s · this provider does not serve it',
            'auth.provider.undeclared' => 'Local model · none declared',
            'auth.provider.local' => 'Local model · %s (%s)',
            'auth.warning.title' => 'Your system user is not a verified identity',
            'auth.warning.desc' => 'Authorizing in a session grants the operation; it is not signing the call.',
            'auth.enter' => 'Open workspace',
            // The conversation, the turn and the composer's commands (greenhouse decisions/0211, phase C).
            // Every sentence those modules say is a key here: they left the page as `tr('…')` calls, and a
            // sentence assembled from fragments in JavaScript cannot be translated as a sentence.
            'verdict.verified' => 'verified',
            'verdict.disputed' => 'disputed',
            'verdict.backed' => "The ledger backs this turn: every completed step carries evidence, nothing was left open, and no artifact's latest check is red.",
            'verdict.disputed.why' => 'The ledger disputes this turn — %s.',
            'verdict.disputed.default' => 'the completion is not backed by evidence',
            'verdict.aria.verified' => 'Verified. %s',
            'verdict.aria.disputed' => 'Disputed. %s',
            'thinking.elapsed' => 'thought for %ss',
            'turn.paused' => 'The agent is waiting on your decision.',
            'turn.stop_requested' => 'stop requested',
            // The session's state, as the topbar badge and the status bar READ it. It is a signal both the
            // server seeds and the turn's module writes, so both say it through this key: an English
            // «Working» written from JavaScript was the one sentence phase C left leaking into a Spanish
            // shell (`ShellController::liveSignals()` seeds the same key, from the same words).
            'session.state.working' => 'Working',
            'session.state.idle' => 'Idle',
            'session.state.waiting' => 'Waiting on you',
            'session.state.paused' => 'Paused',
            'session.state.ended' => 'Ended',
            // The conversation's interrupted-run notice (greenhouse decisions/0196), rendered by the
            // thread's own renderer — the last user-facing sentence the shell hand-wrote in English.
            'conversation.answered' => 'Answered «%s» by %s',
            // The turn's own contents (greenhouse decisions/0254): the request the agent parked, and the
            // boundary a compaction left in the thread.
            'conversation.compacted' => 'context compacted',
            'conversation.compacted.through' => 'context compacted through turn %s',
            'grant.kind.permission' => 'Permission needed',
            'grant.kind.signature' => 'Signature needed',
            'grant.kind.target' => 'Target not named',
            'grant.kind.default' => 'Waiting on you',
            'grant.unnamed' => 'The agent is waiting on a decision.',
            'grant.sending' => 'Sending your answer…',
            'grant.answered' => 'You answered «%s».',
            'grant.answered.by' => 'Answered «%s» by %s.',
            'grant.failed' => 'That answer did not go through: %s',
            'grant.refused' => 'the door refused it',
            'grant.no_session' => 'This page is not driving an agent session, so there is nothing to answer.',
            'grant.no_token' => 'The confirmation gate did not hand back a token.',
            'grant.no_options' => 'The agent proposed no options — answer with «coa agent:answer».',
            // WHAT IS BEING AUTHORIZED, painted (greenhouse decisions/0259). The gate stores the claim as
            // machine data on purpose — it re-reads it to hold consent to these exact arguments — so the
            // surface's job is to paint it. The axis ORDER is the TUI's: most-commonly-tightened first.
            'claim.over' => 'over',
            'claim.axis.authority' => 'authority',
            'claim.axis.reversibility' => 'undo',
            'claim.axis.mutation' => 'changes',
            'claim.axis.externality' => 'reaches',
            'claim.axis.subject' => 'subject',
            'claim.value.privileged' => 'other people\'s resources',
            'claim.value.ordinary' => 'its own',
            'claim.value.persistent' => 'persistent',
            'claim.value.none' => 'nothing',
            'claim.value.third_party' => 'a third party',
            'claim.value.local' => 'this machine',
            'claim.value.manual_recovery' => 'by hand',
            'claim.value.compensatable' => 'compensatable',
            'claim.value.guaranteed' => 'guaranteed',
            'claim.value.executable' => 'what runs',
            'claim.value.configuration' => 'configuration',
            'claim.value.data' => 'data',
            'conversation.sequence_paused' => 'Sequence «%s» paused — answer it in Decisions',
            'conversation.sequence_resumed' => 'Sequence «%s» resumed',
            'conversation.interrupted' => 'A prior run was interrupted — it was left mid-turn and did not finish. Send again to continue; nothing was auto-resumed.',
            // The declared-screen preview: the wire is asked before the frame is pointed at it.
            'preview.failed' => 'The wire does not serve «%s» (HTTP %s)',
            'preview.unreachable' => 'The wire could not be reached for «%s»',
            'composer.tokens' => '~%s tokens',
            'composer.placeholder' => 'Write to the session…',
            'composer.panels_hint' => 'Panels open on their figures, close as you type.',
            'composer.model.asking' => 'asking the endpoint…',
            'composer.model.none' => 'the endpoint served no model',
            'composer.model.unreachable' => 'the endpoint did not answer',
            'composer.model.switched' => 'model switched to %s',
            'composer.model.refused' => 'the model was not switched: %s',
            'composer.send' => 'continue session',
            'composer.stop' => 'stop the turn',
            'command.unknown' => 'unknown command %s — commands: %s',
            'command.mode.usage' => 'usage: /mode ask|acknowledge|auto',
            'command.mode.set' => 'mode %s — applies from the next turn',
            'command.mode.set.auto' => 'mode %s — applies from the next turn (a signature or third-party egress still asks)',
            'command.goal.none' => 'no standing goal — /goal <text> sets one',
            'command.goal.cleared' => 'goal cleared',
            'command.goal.set' => 'goal set: %s',
            'command.goal.unchanged' => 'goal unchanged: %s',
            'op.refused' => '%s refused — %s',
            'op.no_reason' => 'no reason given',
            'op.failed' => '%s → HTTP %s — %s',
            'op.detail' => '%s (%s)',
            'op.hint.not_exposed' => 'the app does not expose %s over HTTP — expose the operation in config/http.php',
            'op.hint.confirm' => "%s asks for confirmation — the house's gate stands; a command does not confirm it",
            'op.hint.unreachable' => 'the operation could not be reached',
            'op.hint.failed' => 'the operation failed',
            // The last inline behaviours to become declared views (greenhouse decisions/0211, phase D):
            // the transport's state, the capabilities two-step, the cross-session inbox, the preview and
            // the screens that used to be raw HTML in the shell's template.
            'conn.degraded' => 'The live hub is not connected — updates arrive on a poll instead of instantly.',
            // Its own key so a translator moves WORDS, never an anchor tag: the renderer wraps this half
            // in the link when this app has a panel to link to (greenhouse decisions/0255).
            'conn.degraded.where' => 'Open the Stack section to start it',
            // The way out for an app with NO panel — every app has this one.
            'conn.degraded.how' => 'See what this app declared with',
            'conn.degraded.command' => 'php bin/coa stack',
            'conn.live' => '◉ live',
            'conn.offline' => '○ offline',
            'conn.connecting' => '○ connecting…',
            'hub.waiting' => 'Waiting on you: %s',
            // The ONLY `cap.*` key left: the sequences/graph confirm flow in `desktop-decisions.js` throws
            // with it when the house issues no token. The rest went with the Capabilities screen, which
            // was a duplicate of the panel's own Plugins section (greenhouse decisions/0290).
            'cap.no_token' => 'the house issued no confirm token',
            'decisions.intro' => 'Decisions an agent has parked for you, across every session — durable questions, not modals. Approve or refuse right here, with your passkey session, in this origin.',
            'decisions.sequences' => 'Sequences',
            'decisions.sequences_intro' => 'The sequences this app declared — a deployment is a list. Run one from here; when a step needs your consent the run pauses, and you answer it right here.',
            'decisions.sequences_empty' => 'This app declares no sequences. Declare one in config/sequences.php.',
            'decisions.run' => 'Run',
            'decisions.approve' => 'Approve',
            'decisions.deny' => 'Deny',
            'decisions.running' => 'running…',
            'decisions.paused_on' => 'paused on %s — answer below',
            'decisions.applied' => 'applied · %s of %s steps ran',
            'decisions.denied' => 'denied · %s',
            'decisions.failed' => 'did not finish · %s',
            'decisions.resuming' => 'answered · resuming…',
            'decisions.stays_paused' => 'refused · the run stays paused',
            'decisions.empty' => 'No decisions to make. When an agent parks a gate, it appears here for you to approve or refuse.',
            'decisions.just_now' => 'just now · open the conversation to answer',
            'decisions.answering' => 'answering…',
            'decisions.answered' => 'answered · the run continued',
            'decisions.refused' => 'that answer was refused: %s',
            'decisions.unnamed' => 'A question is waiting for you.',
            'skills.intro' => 'The skills the agent carries — each guides judgment, it is not a tool that runs. The same list the agent reaches for.',
            'skills.roles' => 'Specialist roles',
            'skills.roles_intro' => 'Specialist agents this app declares — each a named authority with the skills it preloads and the tools it is denied.',
            'subagents.intro' => 'The specialist agents this app declares — each a named authority with the skills it preloads and the tools it is denied. The agent hands work to them; you compose them.',
            'screens.intro' => 'See what the agent is building — a screen it declared, rendered live and hosted here. "How does it look?", answered.',
            'screens.name' => 'screen name',
            'screens.preview' => 'Preview',
            'screens.frame' => 'Live screen preview',
            'statusbar.model' => '%s · local model',
            // THE CONTEXT TAB — what the model actually receives on the next turn (greenhouse decisions/0288).
            'context.window.title' => 'Context window',
            'context.window.meta' => '%1$d%% used · %2$s free',
            'context.window.undeclared' => 'nothing has been sent yet',
            'context.model.title' => 'Model',
            'context.model.none' => 'No model declared — Settings names one',
            'context.model.endpoint' => 'Endpoint',
            'context.model.source' => 'declared in %s',
            'context.model.unreachable' => 'no endpoint declared, so nothing was asked',
            'context.session.title' => 'This session',
            'context.session.turns' => 'Turns',
            'context.session.steps' => 'Steps',
            'context.session.tools' => 'Tool calls',
            'context.session.state' => 'State',
            'context.session.none' => 'No session yet — the composer starts one',
            'context.carries.title' => 'What the agent carries',
            'context.carries.skills' => 'Skills',
            'context.carries.roles' => 'Specialist roles',
            'context.carries.tools' => 'Tools it may call',
            'context.panels.title' => 'Plugin panels',
        ],
        'es' => [
            'chip.gate' => 'puerta: %s',
            'gate.kind.loopback' => 'loopback',
            'gate.kind.custom' => 'propia',
            'gate.kind.passkey' => 'passkey',
            'gate.kind.open' => 'abierta',
            'gate.kind.fallback' => 'respaldo',
            'gate.loopback.title' => 'Sólo loopback',
            'gate.loopback' => 'Este workspace sólo responde a loopback por default. Declara workspace.middleware en config/app.php para ponerlo detrás de tu propia puerta.',
            'topbar.signed_in' => 'sesión iniciada como %s',
            'settings.saved' => 'Guardado',
            'settings.save_failed' => 'No se guardó (HTTP %s)',
            'guard.forbidden' => 'No permitido aquí',
            'guard.forbidden.reason' => 'No permitido aquí (%s)',
            'guard.failed' => 'La petición falló (HTTP %s)',
            'guard.unreachable' => 'No se pudo alcanzar la app',
            'enroll.none' => 'Esta app no tiene puerta de passkey',
            'agent.title' => 'Agente',
            'nav.sessions' => 'Sesiones',
            'nav.decisions' => 'Decisiones',
            'nav.skills' => 'Skills',
            'nav.subagents' => 'Subagentes',
            'tab.chat' => 'Conversación',
            'tab.decisions' => 'Decisiones',
            'tab.work' => 'Trabajo',
            'tab.activity' => 'Actividad',
            'tab.context' => 'Contexto',
            'nav.preview' => 'Vista previa',
            'nav.settings' => 'Ajustes',
            'agent.open' => 'Abrir el Desktop',
            'agent.signin' => 'Inicia sesión para abrir el Agente',
            'agent.signin.action' => 'Iniciar sesión',
            'agent.surface.failed' => 'Esta parte del Agente no se pudo mostrar (%s).',
            'strip.session' => 'Sesión',
            'strip.none' => 'Sin sesión abierta',
            'strip.pick' => 'Elige una sesión',
            'strip.new' => 'Nueva sesión',
            'settings.model.title' => 'Modelo y proveedor',
            'settings.model.provider' => 'Proveedor',
            'settings.model.endpoint' => 'Endpoint',
            'settings.model.endpoint_hint' => 'El endpoint recibe contexto. No ejecuta operaciones.',
            'settings.model.model' => 'Modelo',
            'settings.model.model_hint' => 'Lo que esta app declaró. «Buscar modelos» le pregunta al endpoint qué sirve de verdad — el único control de aquí que sale a la red.',
            'settings.model.model.none' => 'Ninguno declarado',
            'settings.model.model.find' => 'Buscar modelos',
            'settings.model.model.found' => '%s modelo(s) servidos',
            'settings.model.model.unreachable' => 'El endpoint no contestó',
            'settings.model.model.saved' => 'Modelo guardado',
            'settings.model.model.refused' => 'El modelo no se guardó: %s',
            'settings.write.no_policy' => 'Esta app todavía no puede aceptarlos: nada aquí puede decir quién puede reconfigurar al agente. La identidad es lo que acota una escritura así, así que los campos no se ofrecen en vez de fallar cuando le des guardar.',
            'settings.write.no_policy_command' => 'php bin/coa capabilities:enable milpa/auth --sign',
            'settings.write.no_door' => 'La identidad está instalada y no hay puerta montada: sin relying party la ceremonia de passkey no tiene rutas, así que nadie puede iniciar sesión y ninguna escritura puede quedar autorizada. Declárala y los campos aparecen.',
            'settings.write.no_door_command' => "passkey.rpId in config/app.php",
            'settings.model.endpoint.save' => 'Guardar endpoint',
            'settings.model.endpoint.saved' => 'Endpoint guardado',
            'settings.model.endpoint.refused' => 'El endpoint no se guardó: %s',
            'settings.model.key' => 'API key',
            'settings.model.key.hint' => 'Se escribe donde el código la lee y git nunca, por una operación firmada. Nada aquí la puede leer de vuelta.',
            'settings.model.key.placeholder' => 'Pega la llave del proveedor',
            'settings.model.key.replace' => 'Pega una llave nueva para reemplazarla',
            'settings.model.key.held' => 'Hay una llave. Nunca se muestra — reemplázala pegando una nueva.',
            'settings.model.key.save' => 'Guardar llave',
            'settings.model.key.saved' => 'Llave guardada',
            'settings.model.key.refused' => 'La llave no se guardó: %s',
            'settings.autonomy.title' => 'Autonomía por default',
            'settings.autonomy.ask' => 'Preguntar antes de cambiar',
            'settings.autonomy.ask_hint' => 'Pausa las mutaciones que no tienen permiso vigente.',
            'settings.autonomy.acknowledge' => 'Compatibilidad',
            'settings.autonomy.acknowledge_hint' => 'Hoy decide como auto: sin aviso previo observable.',
            'settings.autonomy.auto' => 'Continuar automáticamente',
            'settings.autonomy.auto_hint' => 'Las firmas y la intención incompleta se siguen deteniendo.',
            'settings.autonomy.note' => 'Los tres valores todavía no son tres niveles distintos en su comportamiento.',
            'settings.storage.title' => 'Contexto y almacenamiento',
            'settings.storage.compact_note' => 'La compactación reduce lo que ve el modelo, nunca el registro de la sesión.',
            'settings.storage.folder' => 'Carpeta de sesiones',
            'settings.discard' => 'Descartar cambios',
            'settings.save' => 'Guardar ajustes',
            'auth.kicker' => 'espacio de trabajo local',
            'auth.lede' => 'Abre una app Milpa para empezar, entender y retomar el trabajo de un agente. La sesión es la unidad; nada corre al abrir.',
            'auth.app' => 'App Milpa',
            'auth.app_hint' => 'Lee %s. Una app a la vez.',
            'auth.identity' => 'Identidad de decisión',
            'auth.identity.system' => 'Usuario del sistema',
            'auth.identity.system_hint' => 'No verificado. Las firmas de llamada se piden aparte.',
            'auth.identity.verified' => 'Principal con firma verificada',
            'auth.identity.verified_hint' => 'Requiere un mecanismo externo.',
            'auth.provider' => 'Proveedor de modelo',
            'model.undeclared' => 'sin modelo declarado',
            'model.unreachable' => '%s · no contesta',
            'model.not_served' => '%s · este proveedor no lo sirve',
            'auth.provider.undeclared' => 'Modelo local · ninguno declarado',
            'auth.provider.local' => 'Modelo local · %s (%s)',
            'auth.warning.title' => 'Tu usuario del sistema no es una identidad verificada',
            'auth.warning.desc' => 'Autorizar en una sesión concede la operación; no es firmar la llamada.',
            'auth.enter' => 'Abrir espacio de trabajo',
            'verdict.verified' => 'verificado',
            'verdict.disputed' => 'disputado',
            'verdict.backed' => 'El ledger respalda este turno: cada paso completado carga evidencia, nada quedó abierto y ningún artefacto tiene su última verificación en rojo.',
            'verdict.disputed.why' => 'El ledger disputa este turno — %s.',
            'verdict.disputed.default' => 'la conclusión no está respaldada por evidencia',
            'verdict.aria.verified' => 'Verificado. %s',
            'verdict.aria.disputed' => 'Disputado. %s',
            'thinking.elapsed' => 'pensó por %ss',
            'turn.paused' => 'El agente está esperando tu decisión.',
            'turn.stop_requested' => 'se pidió detener',
            'session.state.working' => 'Trabajando',
            'session.state.idle' => 'Inactivo',
            'session.state.waiting' => 'Te espera',
            'session.state.paused' => 'Pausada',
            'session.state.ended' => 'Terminada',
            'conversation.answered' => 'Contestada «%s» por %s',
            'conversation.compacted' => 'contexto compactado',
            'conversation.compacted.through' => 'contexto compactado hasta el turno %s',
            'grant.kind.permission' => 'Necesita permiso',
            'grant.kind.signature' => 'Necesita firma',
            'grant.kind.target' => 'No nombró el objetivo',
            'grant.kind.default' => 'Te esperan',
            'grant.unnamed' => 'El agente está esperando una decisión.',
            'grant.sending' => 'Enviando tu respuesta…',
            'grant.answered' => 'Contestaste «%s».',
            'grant.answered.by' => 'Contestada «%s» por %s.',
            'grant.failed' => 'Esa respuesta no pasó: %s',
            'grant.refused' => 'la puerta la rechazó',
            'grant.no_session' => 'Esta página no está manejando una sesión del agente, así que no hay qué contestar.',
            'grant.no_token' => 'La compuerta de confirmación no devolvió un token.',
            'grant.no_options' => 'El agente no propuso opciones — contesta con «coa agent:answer».',
            'claim.over' => 'sobre',
            'claim.axis.authority' => 'autoridad',
            'claim.axis.reversibility' => 'deshacer',
            'claim.axis.mutation' => 'cambia',
            'claim.axis.externality' => 'alcanza',
            'claim.axis.subject' => 'sujeto',
            'claim.value.privileged' => 'recursos de otros',
            'claim.value.ordinary' => 'lo propio',
            'claim.value.persistent' => 'de forma persistente',
            'claim.value.none' => 'nada',
            'claim.value.third_party' => 'un tercero',
            'claim.value.local' => 'esta máquina',
            'claim.value.manual_recovery' => 'a mano',
            'claim.value.compensatable' => 'con compensación',
            'claim.value.guaranteed' => 'garantizado',
            'claim.value.executable' => 'lo que corre',
            'claim.value.configuration' => 'configuración',
            'claim.value.data' => 'datos',
            'conversation.sequence_paused' => 'Secuencia «%s» pausada — contéstala en Decisiones',
            'conversation.sequence_resumed' => 'Secuencia «%s» retomada',
            'conversation.interrupted' => 'Una corrida previa quedó interrumpida — se quedó a media vuelta y no terminó. Vuelve a enviar para continuar; nada se retomó solo.',
            'preview.failed' => 'El wire no sirve «%s» (HTTP %s)',
            'preview.unreachable' => 'No se pudo alcanzar el wire para «%s»',
            'composer.tokens' => '~%s tokens',
            'composer.placeholder' => 'Escribe a la sesión…',
            'composer.panels_hint' => 'Los paneles se abren en sus cifras y se cierran al escribir.',
            'composer.model.asking' => 'preguntándole al endpoint…',
            'composer.model.none' => 'el endpoint no sirvió ningún modelo',
            'composer.model.unreachable' => 'el endpoint no contestó',
            'composer.model.switched' => 'modelo cambiado a %s',
            'composer.model.refused' => 'el modelo no se cambió: %s',
            'composer.send' => 'continuar la sesión',
            'composer.stop' => 'detener el turno',
            'command.unknown' => 'comando desconocido %s — comandos: %s',
            'command.mode.usage' => 'uso: /mode ask|acknowledge|auto',
            'command.mode.set' => 'modo %s — aplica desde el siguiente turno',
            'command.mode.set.auto' => 'modo %s — aplica desde el siguiente turno (una firma o un egreso a terceros sigue preguntando)',
            'command.goal.none' => 'no hay meta vigente — /goal <texto> pone una',
            'command.goal.cleared' => 'meta borrada',
            'command.goal.set' => 'meta puesta: %s',
            'command.goal.unchanged' => 'meta sin cambio: %s',
            'op.refused' => '%s rechazó — %s',
            'op.no_reason' => 'sin razón declarada',
            'op.failed' => '%s → HTTP %s — %s',
            'op.detail' => '%s (%s)',
            'op.hint.not_exposed' => 'la app no expone %s por HTTP — expón la operación en config/http.php',
            'op.hint.confirm' => '%s pide confirmación — la puerta de la casa sigue en pie; un comando no la confirma',
            'op.hint.unreachable' => 'no se pudo alcanzar la operación',
            'op.hint.failed' => 'la operación falló',
            'conn.degraded' => 'El hub en vivo no está conectado — las actualizaciones llegan por sondeo, no al instante.',
            'conn.degraded.where' => 'Abre la sección Stack para levantarlo',
            'conn.degraded.how' => 'Mira lo que esta app declaró con',
            'conn.degraded.command' => 'php bin/coa stack',
            'conn.live' => '◉ en vivo',
            'conn.offline' => '○ sin conexión',
            'conn.connecting' => '○ conectando…',
            'hub.waiting' => 'Te esperan: %s',
            'cap.no_token' => 'la casa no emitió token de confirmación',
            'decisions.intro' => 'Decisiones que un agente dejó pendientes para ti, en todas las sesiones — preguntas duraderas, no modales. Aprueba o rechaza aquí mismo, con tu sesión de passkey, en este origen.',
            'decisions.sequences' => 'Secuencias',
            'decisions.sequences_intro' => 'Las secuencias que esta app declaró — un despliegue es una lista. Corre una desde aquí; cuando un paso necesita tu consentimiento la corrida se pausa, y la contestas aquí mismo.',
            'decisions.sequences_empty' => 'Esta app no declara secuencias. Declara una en config/sequences.php.',
            'decisions.run' => 'Correr',
            'decisions.approve' => 'Aprobar',
            'decisions.deny' => 'Rechazar',
            'decisions.running' => 'corriendo…',
            'decisions.paused_on' => 'pausada en %s — contesta abajo',
            'decisions.applied' => 'aplicada · corrieron %s de %s pasos',
            'decisions.denied' => 'negada · %s',
            'decisions.failed' => 'no terminó · %s',
            'decisions.resuming' => 'contestada · retomando…',
            'decisions.stays_paused' => 'rechazada · la corrida sigue pausada',
            'decisions.empty' => 'No hay decisiones que tomar. Cuando un agente deja un gate, aparece aquí para que apruebes o rechaces.',
            'decisions.just_now' => 'ahora · abre la conversación para responder',
            'decisions.answering' => 'contestando…',
            'decisions.answered' => 'contestada · la corrida siguió',
            'decisions.refused' => 'esa respuesta se rechazó: %s',
            'decisions.unnamed' => 'Hay una pregunta esperándote.',
            'skills.intro' => 'Las skills que carga el agente — cada una guía el criterio, no es una herramienta que corre. La misma lista a la que echa mano el agente.',
            'skills.roles' => 'Roles especialistas',
            'skills.roles_intro' => 'Agentes especialistas que esta app declara — cada uno una autoridad con nombre, con las skills que precarga y las herramientas que tiene negadas.',
            'subagents.intro' => 'Los agentes especialistas que esta app declara — cada uno una autoridad con nombre, con las skills que precarga y las herramientas que tiene negadas. El agente les pasa trabajo; tú los compones.',
            'screens.intro' => 'Mira lo que el agente está construyendo — una pantalla que declaró, renderizada en vivo y hospedada aquí. «¿Cómo se ve?», contestado.',
            'screens.name' => 'nombre de pantalla',
            'screens.preview' => 'Previsualizar',
            'screens.frame' => 'Vista previa de la pantalla en vivo',
            'statusbar.model' => '%s · modelo local',
            // LA PESTAÑA CONTEXT — lo que el modelo recibe de verdad en el próximo turno (decisions/0288).
            'context.window.title' => 'Ventana de contexto',
            'context.window.meta' => '%1$d%% usada · %2$s libre',
            'context.window.undeclared' => 'todavía no se ha mandado nada',
            'context.model.title' => 'Modelo',
            'context.model.none' => 'Ningún modelo declarado — en Settings se nombra uno',
            'context.model.endpoint' => 'Endpoint',
            'context.model.source' => 'declarado en %s',
            'context.model.unreachable' => 'sin endpoint declarado, así que no se preguntó nada',
            'context.session.title' => 'Esta sesión',
            'context.session.turns' => 'Turnos',
            'context.session.steps' => 'Pasos',
            'context.session.tools' => 'Llamadas a herramientas',
            'context.session.state' => 'Estado',
            'context.session.none' => 'Todavía no hay sesión — el composer abre una',
            'context.carries.title' => 'Lo que el agente lleva',
            'context.carries.skills' => 'Skills',
            'context.carries.roles' => 'Roles especialistas',
            'context.carries.tools' => 'Herramientas que puede llamar',
            'context.panels.title' => 'Paneles de plugins',
        ],
    ];

    private string $locale;

    public function __construct(string $locale = self::DEFAULT_LOCALE)
    {
        $this->locale = isset(self::MESSAGES[$locale]) ? $locale : self::DEFAULT_LOCALE;
    }

    /** The message for a key in the catalog's locale, with `sprintf` arguments applied; the key itself when unknown. */
    public function tr(string $key, string ...$args): string
    {
        $message = self::MESSAGES[$this->locale][$key] ?? self::MESSAGES[self::DEFAULT_LOCALE][$key] ?? $key;

        return $args === [] ? $message : vsprintf($message, $args);
    }

    /** True when the catalog knows the key in its locale or in English. */
    public function has(string $key): bool
    {
        return isset(self::MESSAGES[$this->locale][$key]) || isset(self::MESSAGES[self::DEFAULT_LOCALE][$key]);
    }

    /** The locale this catalog answers in. */
    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Every message of the catalog's locale, English filling the gaps — what the shell hands its client
     * script, so the browser says the same words the server does.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return self::MESSAGES[$this->locale] + self::MESSAGES[self::DEFAULT_LOCALE];
    }

    /**
     * The locales the catalog carries.
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        return array_keys(self::MESSAGES);
    }
}
