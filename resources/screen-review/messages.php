<?php
/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
return [
'en'=>[
 'title'=>'Review before going live','intro'=>'Try a screen with separate test data, then activate the revision you reviewed.',
 'drafts'=>'Saved revisions','new'=>'Create a revision','name'=>'Screen name','type'=>'Component type','props'=>'Screen settings (JSON)','save'=>'Save revision',
 'before'=>'Baseline when this revision was created','after'=>'Proposed screen','active'=>'Open active screen','preview'=>'Isolated preview',
 'promote'=>'Activate this revision','rollback'=>'Restore its baseline','fresh'=>'The active screen still matches this baseline.',
 'stale'=>'The active screen has changed. Create a new revision to compare with it.','restorable'=>'This declaration is active. Restoring its baseline keeps application data.',
 'empty'=>'Create a revision to preview and compare it.','isolation'=>'Preview actions use test data. Activation and restore change the screen declaration; they do not copy test data.',
 'draft_saved'=>'Revision saved. Review the changes and try the preview.','promoted'=>'The reviewed revision is now active. Application data was preserved.',
 'restored'=>'The baseline screen was restored. Application data was preserved.','base_changed'=>'The active screen changed. Review a new revision before activating.',
 'build_changed'=>'The app code or configuration changed. Create a new revision to review this build.','revision_changed'=>'This revision changed or no longer matches the selection.',
 'revision_missing'=>'That revision is unavailable.','invalid_name'=>'Use a screen name beginning with a letter and containing letters, digits or dashes.',
 'invalid_props'=>'Enter a JSON object for the screen settings.','preview_not_configured'=>'This component has no isolated preview factory.',
 'error'=>'The change could not be completed. Review the current state and try again.','language'=>'Español',
],
'es'=>[
 'title'=>'Revisa antes de activar','intro'=>'Prueba una pantalla con datos separados y activa la revisión que viste.',
 'drafts'=>'Revisiones guardadas','new'=>'Crear una revisión','name'=>'Nombre de la pantalla','type'=>'Tipo de componente','props'=>'Configuración de pantalla (JSON)','save'=>'Guardar revisión',
 'before'=>'Base al crear esta revisión','after'=>'Pantalla propuesta','active'=>'Abrir pantalla activa','preview'=>'Preview aislado',
 'promote'=>'Activar esta revisión','rollback'=>'Restaurar su base','fresh'=>'La pantalla activa todavía coincide con esta base.',
 'stale'=>'La pantalla activa cambió. Crea una revisión nueva para compararla.','restorable'=>'Esta declaración está activa. Restaurar su base conserva los datos de la app.',
 'empty'=>'Crea una revisión para verla y compararla.','isolation'=>'Las acciones del Preview usan datos de prueba. Activar y restaurar cambian la declaración; no copian esos datos.',
 'draft_saved'=>'Revisión guardada. Revisa los cambios y prueba el Preview.','promoted'=>'La revisión que viste ya está activa. Se conservaron los datos de la app.',
 'restored'=>'Se restauró la pantalla base y se conservaron los datos de la app.','base_changed'=>'La pantalla activa cambió. Revisa una versión nueva antes de activar.',
 'build_changed'=>'El código o la configuración cambiaron. Crea una revisión nueva para ver esta versión.',
 'revision_changed'=>'Esta revisión cambió o ya no coincide con la selección.','revision_missing'=>'Esa revisión no está disponible.',
 'invalid_name'=>'El nombre debe comenzar con letra y contener letras, números o guiones.','invalid_props'=>'Escribe un objeto JSON para la configuración.',
 'preview_not_configured'=>'Este componente no tiene una fábrica de Preview aislado.','error'=>'No se pudo completar el cambio. Revisa el estado actual e inténtalo de nuevo.','language'=>'English',
],
];
