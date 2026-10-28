<script setup lang="ts">
// Quick shift logging: one form for date / start / end / client / notes,
// plus hour totals for this week, last week, the fortnight and the month.
// Built to work well on a phone.
import AppLayout from '@/Layouts/AppLayout.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { computed, reactive, ref } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { api, type Client, type Project, type TimeEntry } from '@/packages/api/src';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useClientsStore } from '@/utils/useClients';
import { useTimeEntriesMutations } from '@/utils/useTimeEntriesMutations';
import { fetchAllCalendarEntries } from '@/utils/useTimeEntriesCalendarQuery';
import { getCurrentMembershipId, getCurrentOrganizationId } from '@/utils/useUser';
import {
    getDayJsInstance,
    getLocalizedDayJs,
    getLocalizedDayJsFromMinutes,
    localDateToUtc,
} from '@/packages/ui/src/utils/time';
import { getRandomColorWithSeed } from '@/packages/ui/src/utils/color';
import { PencilSquareIcon, TrashIcon } from '@heroicons/vue/20/solid';

const dayjs = getDayJsInstance();
const queryClient = useQueryClient();

const { clients } = useClientsQuery();
const { projects, refetch: refetchProjects } = useProjectsQuery();
const { createTimeEntry, updateTimeEntry, deleteTimeEntry } = useTimeEntriesMutations();

// ---------- Date ranges (all as local YYYY-MM-DD strings) ----------

const DATE = 'YYYY-MM-DD';
const today = getLocalizedDayJs().format(DATE);
const yesterday = dayjs(today).subtract(1, 'day').format(DATE);
const thisWeekStart = dayjs(today).startOf('week').format(DATE);
const lastWeekStart = dayjs(thisWeekStart).subtract(7, 'day').format(DATE);
const monthStart = dayjs(today).startOf('month').format(DATE);
const rangeStart = lastWeekStart < monthStart ? lastWeekStart : monthStart;
const rangeEnd = dayjs(today).add(8, 'day').format(DATE);

const { data: entriesResponse, isLoading } = useQuery({
    queryKey: ['timeEntries', 'shifts', { rangeStart, rangeEnd }],
    enabled: () => !!getCurrentOrganizationId(),
    queryFn: () =>
        fetchAllCalendarEntries(
            getCurrentOrganizationId() || '',
            getCurrentMembershipId(),
            localDateToUtc(dayjs(rangeStart)),
            localDateToUtc(dayjs(rangeEnd))
        ),
    staleTime: 1000 * 30,
});

const entries = computed<TimeEntry[]>(() =>
    (entriesResponse.value?.data ?? [])
        .filter((e) => e.type !== 'break')
        .slice()
        .sort((a, b) => (a.start < b.start ? 1 : -1))
);

function entrySeconds(entry: TimeEntry): number {
    if (entry.duration !== null) return entry.duration;
    return dayjs().diff(dayjs(entry.start), 'second');
}

function entryDate(entry: TimeEntry): string {
    return getLocalizedDayJs(entry.start).format(DATE);
}

function sumBetween(from: string, toExclusive: string): number {
    return entries.value
        .filter((e) => {
            const d = entryDate(e);
            return d >= from && d < toExclusive;
        })
        .reduce((total, e) => total + entrySeconds(e), 0);
}

const nextWeekStart = dayjs(thisWeekStart).add(7, 'day').format(DATE);
const nextMonthStart = dayjs(monthStart).add(1, 'month').format(DATE);

const stats = computed(() => [
    { label: 'This week', seconds: sumBetween(thisWeekStart, nextWeekStart) },
    { label: 'Last week', seconds: sumBetween(lastWeekStart, thisWeekStart) },
    { label: 'Fortnight', seconds: sumBetween(lastWeekStart, nextWeekStart) },
    { label: dayjs(today).format('MMMM'), seconds: sumBetween(monthStart, nextMonthStart) },
]);

function hours(seconds: number): string {
    const h = Math.round((seconds / 3600) * 100) / 100;
    return `${h}h`;
}

// ---------- Client <-> project lookup ----------
// Time entries link to a client through a project, so each client gets one
// project (same name) that is created automatically the first time it's used.

const projectById = computed(() => new Map(projects.value.map((p) => [p.id, p])));
const clientById = computed(() => new Map(clients.value.map((c) => [c.id, c])));

function clientIdOf(entry: TimeEntry): string | null {
    if (!entry.project_id) return null;
    return projectById.value.get(entry.project_id)?.client_id ?? null;
}

function clientLabel(entry: TimeEntry): string {
    const clientId = clientIdOf(entry);
    if (clientId) return clientById.value.get(clientId)?.name ?? 'Unknown client';
    if (entry.project_id) return projectById.value.get(entry.project_id)?.name ?? 'No client';
    return 'No client';
}

function findProjectForClient(client: Client): Project | undefined {
    const candidates = projects.value.filter((p) => p.client_id === client.id && !p.is_archived);
    return candidates.find((p) => p.name === client.name) ?? candidates[0];
}

async function projectIdForClient(clientId: string | null): Promise<string | null> {
    if (!clientId) return null;
    const client = clientById.value.get(clientId);
    if (!client) return null;
    const existing = findProjectForClient(client);
    if (existing) return existing.id;

    const organizationId = getCurrentOrganizationId();
    if (!organizationId) return null;
    const response = await api.createProject(
        {
            name: client.name,
            color: getRandomColorWithSeed(client.name),
            is_billable: true,
            client_id: client.id,
        },
        { params: { organization: organizationId } }
    );
    await queryClient.invalidateQueries({ queryKey: ['projects'] });
    await refetchProjects();
    return response.data.id;
}

const clientsByPeriod = computed(() => {
    const totals = new Map<string, number>();
    for (const e of entries.value) {
        const d = entryDate(e);
        if (d < lastWeekStart || d >= nextWeekStart) continue;
        const label = clientLabel(e);
        totals.set(label, (totals.get(label) ?? 0) + entrySeconds(e));
    }
    return [...totals.entries()].sort((a, b) => b[1] - a[1]);
});

const clientOptions = computed(() =>
    clients.value
        .filter((c) => !c.is_archived || c.id === form.clientId)
        .slice()
        .sort((a, b) => a.name.localeCompare(b.name))
);

// ---------- Form ----------

const NEW_CLIENT = '__new__';
const STORAGE_KEY = 'shifts.lastUsed';

function loadLastUsed(): { clientId?: string; start?: string; end?: string } {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}');
    } catch {
        return {};
    }
}

function saveLastUsed() {
    try {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ clientId: form.clientId, start: form.start, end: form.end })
        );
    } catch {
        // Storage unavailable (private mode etc.) - not important.
    }
}

const lastUsed = loadLastUsed();

const form = reactive({
    date: today,
    start: lastUsed.start ?? '09:00',
    end: lastUsed.end ?? '17:00',
    clientId: lastUsed.clientId ?? '',
    newClientName: '',
    notes: '',
});

const editing = ref<TimeEntry | null>(null);
const saving = ref(false);
const error = ref('');

function toMinutes(time: string): number | null {
    const match = /^(\d{1,2}):(\d{2})/.exec(time);
    if (!match) return null;
    return Number(match[1]) * 60 + Number(match[2]);
}

const shiftMinutes = computed(() => {
    const start = toMinutes(form.start);
    let end = toMinutes(form.end);
    if (start === null || end === null) return null;
    if (end <= start) end += 24 * 60;
    return { start, end, overnight: end > 24 * 60 };
});

const shiftLength = computed(() => {
    if (!shiftMinutes.value) return '';
    return hours((shiftMinutes.value.end - shiftMinutes.value.start) * 60);
});

function resetForm() {
    editing.value = null;
    form.notes = '';
    form.newClientName = '';
    form.date = today;
    error.value = '';
}

function startEdit(entry: TimeEntry) {
    editing.value = entry;
    form.date = entryDate(entry);
    form.start = getLocalizedDayJs(entry.start).format('HH:mm');
    form.end = entry.end ? getLocalizedDayJs(entry.end).format('HH:mm') : form.end;
    form.clientId = clientIdOf(entry) ?? '';
    form.notes = entry.description ?? '';
    error.value = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
    document.querySelector('main')?.parentElement?.scrollTo({ top: 0, behavior: 'smooth' });
}

async function resolveClientId(): Promise<string | null> {
    if (form.clientId !== NEW_CLIENT) return form.clientId || null;
    const name = form.newClientName.trim();
    if (!name) throw new Error('Enter a name for the new client.');
    const client = await useClientsStore().createClient({ name });
    if (!client) throw new Error('Could not create the client.');
    await queryClient.refetchQueries({ queryKey: ['clients'] });
    form.clientId = client.id;
    form.newClientName = '';
    return client.id;
}

async function save() {
    error.value = '';
    if (!shiftMinutes.value) {
        error.value = 'Enter a start and end time.';
        return;
    }
    saving.value = true;
    try {
        const clientId = await resolveClientId();
        const projectId = await projectIdForClient(clientId);
        const project = projectId ? projectById.value.get(projectId) : undefined;
        const start = getLocalizedDayJsFromMinutes(form.date, shiftMinutes.value.start)
            .utc()
            .format();
        const end = getLocalizedDayJsFromMinutes(form.date, shiftMinutes.value.end).utc().format();
        const description = form.notes.trim() || null;
        const billable = project?.is_billable ?? true;

        if (editing.value) {
            const sameProject = editing.value.project_id === projectId;
            await updateTimeEntry({
                ...editing.value,
                start,
                end,
                description,
                project_id: projectId,
                task_id: sameProject ? editing.value.task_id : null,
                billable: sameProject ? editing.value.billable : billable,
            });
        } else {
            await createTimeEntry({
                start,
                end,
                description,
                project_id: projectId,
                task_id: null,
                billable,
                tags: [],
            });
        }
        saveLastUsed();
        resetForm();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Something went wrong saving the shift.';
    } finally {
        saving.value = false;
    }
}

async function remove(entry: TimeEntry) {
    if (!window.confirm(`Delete this ${clientLabel(entry)} shift?`)) return;
    await deleteTimeEntry(entry.id);
    if (editing.value?.id === entry.id) resetForm();
}

// ---------- Recent shifts list ----------

const groupedShifts = computed(() => {
    const groups = new Map<string, TimeEntry[]>();
    for (const e of entries.value) {
        const d = entryDate(e);
        if (!groups.has(d)) groups.set(d, []);
        groups.get(d)!.push(e);
    }
    return [...groups.entries()].map(([date, items]) => ({
        date,
        label:
            date === today
                ? 'Today'
                : date === yesterday
                  ? 'Yesterday'
                  : dayjs(date).format('ddd D MMM'),
        total: items.reduce((t, e) => t + entrySeconds(e), 0),
        items,
    }));
});

function timeRange(entry: TimeEntry): string {
    const start = getLocalizedDayJs(entry.start).format('HH:mm');
    if (!entry.end) return `${start} – running`;
    return `${start} – ${getLocalizedDayJs(entry.end).format('HH:mm')}`;
}

const inputClass =
    'w-full h-11 sm:h-9 px-3 rounded-md border border-input-border bg-input-background text-text-primary text-base sm:text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:border-transparent';
const labelClass = 'block text-xs font-medium text-text-secondary mb-1';
</script>

<template>
    <AppLayout title="Shifts" data-testid="shifts_view">
        <MainContainer class="pt-4 sm:pt-6 pb-8 max-w-2xl space-y-6">
            <!-- Totals -->
            <section class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                <div
                    v-for="stat in stats"
                    :key="stat.label"
                    class="rounded-lg bg-card-background border border-card-border px-3 py-2.5">
                    <div class="text-xs text-text-tertiary">{{ stat.label }}</div>
                    <div class="text-xl font-semibold text-text-primary tabular-nums">
                        {{ isLoading ? '–' : hours(stat.seconds) }}
                    </div>
                </div>
            </section>

            <!-- Log / edit a shift -->
            <form
                class="rounded-lg bg-card-background border border-card-border p-4 space-y-3"
                @submit.prevent="save">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-text-primary">
                        {{ editing ? 'Edit shift' : 'Log a shift' }}
                    </h2>
                    <div class="flex gap-1">
                        <button
                            type="button"
                            class="text-xs px-2 py-1 rounded-md border border-card-border"
                            :class="
                                form.date === today
                                    ? 'bg-card-background-active text-text-primary'
                                    : 'text-text-secondary'
                            "
                            @click="form.date = today">
                            Today
                        </button>
                        <button
                            type="button"
                            class="text-xs px-2 py-1 rounded-md border border-card-border"
                            :class="
                                form.date === yesterday
                                    ? 'bg-card-background-active text-text-primary'
                                    : 'text-text-secondary'
                            "
                            @click="form.date = yesterday">
                            Yesterday
                        </button>
                    </div>
                </div>

                <div>
                    <label for="shift-date" :class="labelClass">Date</label>
                    <input
                        id="shift-date"
                        v-model="form.date"
                        type="date"
                        required
                        :class="inputClass" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="shift-start" :class="labelClass">Start</label>
                        <input
                            id="shift-start"
                            v-model="form.start"
                            type="time"
                            required
                            :class="inputClass" />
                    </div>
                    <div>
                        <label for="shift-end" :class="labelClass">Finish</label>
                        <input
                            id="shift-end"
                            v-model="form.end"
                            type="time"
                            required
                            :class="inputClass" />
                    </div>
                </div>
                <p v-if="shiftLength" class="text-xs text-text-tertiary -mt-1">
                    {{ shiftLength
                    }}<span v-if="shiftMinutes?.overnight"> · finishes next day</span>
                </p>

                <div>
                    <label for="shift-client" :class="labelClass">Client</label>
                    <select id="shift-client" v-model="form.clientId" :class="inputClass">
                        <option value="">No client</option>
                        <option v-for="client in clientOptions" :key="client.id" :value="client.id">
                            {{ client.name }}
                        </option>
                        <option :value="NEW_CLIENT">+ New client…</option>
                    </select>
                    <input
                        v-if="form.clientId === NEW_CLIENT"
                        v-model="form.newClientName"
                        type="text"
                        placeholder="New client name"
                        :class="[inputClass, 'mt-2']" />
                </div>

                <div>
                    <label for="shift-notes" :class="labelClass">Shift notes</label>
                    <textarea
                        id="shift-notes"
                        v-model="form.notes"
                        rows="3"
                        placeholder="Venue, role, what you did…"
                        :class="[inputClass, 'h-auto py-2']"></textarea>
                </div>

                <p v-if="error" class="text-sm text-red-400">{{ error }}</p>

                <div class="flex gap-2">
                    <PrimaryButton
                        :loading="saving"
                        class="flex-1 justify-center"
                        data-testid="save_shift">
                        {{ editing ? 'Save changes' : 'Save shift' }}
                    </PrimaryButton>
                    <SecondaryButton v-if="editing" type="button" @click="resetForm">
                        Cancel
                    </SecondaryButton>
                </div>
            </form>

            <!-- Fortnight by client -->
            <section v-if="clientsByPeriod.length">
                <h2 class="text-xs font-semibold text-text-tertiary mb-1.5">Fortnight by client</h2>
                <div
                    class="rounded-lg bg-card-background border border-card-border divide-y divide-card-border">
                    <div
                        v-for="[name, seconds] in clientsByPeriod"
                        :key="name"
                        class="flex justify-between px-3 py-2 text-sm">
                        <span class="text-text-primary truncate">{{ name }}</span>
                        <span class="tabular-nums">{{ hours(seconds) }}</span>
                    </div>
                </div>
            </section>

            <!-- Recent shifts -->
            <section>
                <h2 class="text-xs font-semibold text-text-tertiary mb-1.5">Recent shifts</h2>
                <p v-if="!isLoading && groupedShifts.length === 0" class="text-sm">
                    No shifts logged yet.
                </p>
                <div class="space-y-4">
                    <div v-for="group in groupedShifts" :key="group.date">
                        <div class="flex justify-between text-xs text-text-tertiary mb-1 px-1">
                            <span>{{ group.label }}</span>
                            <span class="tabular-nums">{{ hours(group.total) }}</span>
                        </div>
                        <div
                            class="rounded-lg bg-card-background border border-card-border divide-y divide-card-border">
                            <div
                                v-for="entry in group.items"
                                :key="entry.id"
                                class="flex items-start gap-2 px-3 py-2.5"
                                :class="
                                    editing?.id === entry.id ? 'bg-card-background-active' : ''
                                ">
                                <div class="flex-1 min-w-0">
                                    <div class="flex justify-between gap-2 text-sm">
                                        <span class="font-medium text-text-primary truncate">
                                            {{ clientLabel(entry) }}
                                        </span>
                                        <span class="tabular-nums shrink-0">
                                            {{ hours(entrySeconds(entry)) }}
                                        </span>
                                    </div>
                                    <div class="text-xs text-text-tertiary tabular-nums">
                                        {{ timeRange(entry) }}
                                    </div>
                                    <div
                                        v-if="entry.description"
                                        class="text-xs mt-1 whitespace-pre-line break-words">
                                        {{ entry.description }}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="p-2 -m-1 text-icon-default hover:text-text-primary"
                                    aria-label="Edit shift"
                                    @click="startEdit(entry)">
                                    <PencilSquareIcon class="w-4 h-4" />
                                </button>
                                <button
                                    type="button"
                                    class="p-2 -m-1 text-icon-default hover:text-red-400"
                                    aria-label="Delete shift"
                                    @click="remove(entry)">
                                    <TrashIcon class="w-4 h-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </MainContainer>
    </AppLayout>
</template>
