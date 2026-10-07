# Reading session: a complete composition

This small screen tracks pages read toward a session goal. It demonstrates a custom HTML renderer
composed with Milpa's native `input`, server actions, signed state, English/Spanish messages and an
isolated preview graph. It keeps state in the signed session envelope; refreshing the initial
screen starts again. An application requiring durable records still needs its own repository.

Read these files with `source_read`, using the base directory returned by `skill_load`:

- the PHP block in `SKILL.md`: the complete renderer, already delivered by `skill_load`; start here
  when the component behavior already exists. It consumes supplied state, obtains the native
  input from the registries, forwards context and returns both resource channels.
- `references/reading-session/ReadingSession.php`: state and action contracts. The parent owns
  the goal; submitting the native input calls the parent's `goal` action with current FormData.
- `references/reading-session/messages.php` and `references/reading-session/session.css`:
  catalogued messages and a responsive layout declared through `ComponentPresentation`.
- `references/reading-session/ReadingSessionPlugin.php`: registers the live graph and rebuilds
  the entire preview graph with its own codec. Registration makes a type available; it does
  not activate a screen or authorize a user.

Every file is complete PHP/CSS, not pseudocode. To try the sample in a disposable app, copy the
four sibling files into `src/ReadingSession/`, save the PHP block from `SKILL.md` as
`src/ReadingSession/ReadingSessionRenderer.php`, and register `App\ReadingSession\ReadingSessionPlugin`
alongside the app's live plugin, and use the house's draft tools with type `reading-session` and
props `{"title":"A chapter before lunch","locale":"en"}`. Keep the app's normal authentication
and approval flow. Obtain the exact review URL from the draft result; do not invent one.

For another renderer, keep the pattern but choose its real contract, fields, actions and layout:
the root's ID, `milpaComponent` configuration and encoded state share one identity; each native
child has a distinct ID; the parent form reads current inputs at submission. A component with
server-owned form values uses `storage: none` to avoid an old browser value replacing the result.
The codec's already-encoded envelope goes into the state script unchanged. JSON configuration
is HTML-escaped once by `Html::attrs`. User text is escaped; child renderer HTML is already HTML.

Build and verify the requested renderer through `implement`/`edit` and promote its successful
trial. A working sample is a reference, not evidence that the requested application is complete.

Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
