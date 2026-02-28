<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\Translation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * LanguageController.
 *
 * Manages platform languages and their translation strings.
 * Supports CRUD for languages, default language selection,
 * and bulk translation editing.
 */
class LanguageController extends Controller
{
    /**
     * Display a listing of languages.
     */
    public function index(): InertiaResponse
    {
        $languages = Language::withCount('translations')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Admin/Languages/Index', [
            'languages' => $languages,
        ]);
    }

    /**
     * Store a newly created language.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:10', 'unique:languages,code'],
            'name' => ['required', 'string', 'max:100'],
            'native_name' => ['required', 'string', 'max:100'],
            'flag_emoji' => ['nullable', 'string', 'max:10'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        Language::create($validated);

        return back()->with('success', 'Language created successfully.');
    }

    /**
     * Update the specified language.
     */
    public function update(Request $request, Language $language): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:10', "unique:languages,code,{$language->id}"],
            'name' => ['required', 'string', 'max:100'],
            'native_name' => ['required', 'string', 'max:100'],
            'flag_emoji' => ['nullable', 'string', 'max:10'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $language->update($validated);

        return back()->with('success', 'Language updated successfully.');
    }

    /**
     * Set a language as the default.
     *
     * Unsets the current default first, then applies the new one.
     */
    public function setDefault(Language $language): \Illuminate\Http\RedirectResponse
    {
        DB::transaction(function () use ($language) {
            // Remove current default
            Language::where('is_default', true)->update(['is_default' => false]);

            // Set new default
            $language->update([
                'is_default' => true,
                'is_active' => true,
            ]);
        });

        return back()->with('success', "{$language->name} is now the default language.");
    }

    /**
     * Display the translations editor for a specific language.
     */
    public function translations(Language $language): InertiaResponse
    {
        $translations = $language->translations()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->groupBy('group')
            ->map(function ($items) {
                return $items->mapWithKeys(function ($item) {
                    return [$item->key => $item->value];
                });
            });

        return Inertia::render('Admin/Languages/Translations', [
            'language' => $language,
            'translations' => $translations,
        ]);
    }

    /**
     * Bulk update translations for a language.
     *
     * Expects a nested array of translations keyed by "group.key".
     */
    public function updateTranslations(Request $request, Language $language): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'translations' => ['required', 'array'],
            'translations.*' => ['nullable', 'string', 'max:10000'],
        ]);

        DB::transaction(function () use ($language, $validated) {
            foreach ($validated['translations'] as $groupKey => $value) {
                if (! str_contains($groupKey, '.')) {
                    continue;
                }

                [$group, $key] = explode('.', $groupKey, 2);

                Translation::updateOrCreate(
                    [
                        'language_id' => $language->id,
                        'group' => $group,
                        'key' => $key,
                    ],
                    ['value' => $value]
                );
            }
        });

        // Clear translation cache for this language
        $language->translations()->get()->each(function ($translation) use ($language) {
            Cache::forget("translations.{$language->code}.{$translation->group}.{$translation->key}");
        });

        return back()->with('success', 'Translations updated successfully.');
    }

    /**
     * Remove the specified language.
     *
     * Prevents deletion of the default language.
     */
    public function destroy(Language $language): \Illuminate\Http\RedirectResponse
    {
        if ($language->is_default) {
            return back()->with('error', 'Cannot delete the default language. Please set another language as default first.');
        }

        $language->delete();

        return back()->with('success', 'Language deleted successfully.');
    }
}
