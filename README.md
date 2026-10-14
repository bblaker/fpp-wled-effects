# WLED Effects for FPP

An [FPP (Falcon Player)](https://github.com/FalconChristmas/fpp) plugin that makes FPP the single place
you control your [WLED](https://kno.wled.ge/) controllers from.

It reads the **effects, palettes, presets and playlists** saved on each WLED controller and exposes them
as **FPP Commands**. Anything in FPP that runs a command can then drive WLED: playlist entries, the
scheduler, command presets, GPIO inputs, MQTT and so on. You get scheduled, sequenced WLED looks
without building xLights sequences for them.

## Features

- **Uses the WLED controllers FPP already knows about.** Controllers found by FPP's MultiSync discovery
  and the destinations of your E1.31/DDP/ArtNet channel outputs are checked for WLED and added
  automatically. You can also add one by IP address or hostname.
- **FPP Commands** (show up in every FPP command picker, with dropdowns filled from your devices):

  | Command | Arguments |
  | --- | --- |
  | `WLED - Apply Preset` | Device, Preset, Override FPP Live Data |
  | `WLED - Start Playlist` | Device, WLED Playlist, Override FPP Live Data |
  | `WLED - Run Effect` | Device, Effect, Palette, Color 1-3, Speed, Intensity, Brightness, Segments, Override FPP Live Data |
  | `WLED - Power` | Device, On / Off / Toggle |
  | `WLED - Brightness` | Device, Brightness (0-255) |
  | `WLED - Live Override` | Device, Off / Until Live Data Ends / Until Reboot |

  `Device` can be one controller or **All WLED Devices**. Presets, playlists and effects are chosen by
  name, so one command can apply the same-named preset on several controllers even when the preset IDs
  differ.
- **WLED Effects page** (Content Setup > WLED Effects): manage devices, browse and try presets, playlists
  and effects, set power, brightness and live override, and **Add to FPP Playlist** with one click
  (existing or new playlist, optional hold time that adds a Pause entry after the command).

## Install

In FPP go to **Content Setup > Plugin Manager**, paste
`https://raw.githubusercontent.com/bblaker/fpp-wled-effects/main/pluginInfo.json` into the plugin URL box
and install. Restart FPPD when FPP asks, so the new commands are registered.

Requires FPP 8.0 or later. No extra packages are installed.

## Using it

1. Open **Content Setup > WLED Effects**. WLED controllers FPP knows about are added for you; use
   **Find WLED Devices** or **Add Device** for the rest.
2. Try presets and effects from the **Control** panel.
3. Press **Add to FPP Playlist** next to anything you want to schedule. Or, in FPP's playlist editor, add an
   **FPP Command** entry and pick one of the `WLED - …` commands.
4. FPP Commands finish immediately. Follow one with a **Pause** entry for as long as you want the look to
   stay up, then schedule the playlist as usual.

### Notes

- If FPP streams pixel data to a WLED while a sequence plays, WLED shows that live data instead of its own
  effects. Tick **Override FPP Live Data** on the command, or run `WLED - Live Override`, to show the WLED
  effect anyway.
- WLED ignores a request to apply the preset that is already active.
- Commands give up on a controller that hasn't answered within about 3 seconds, so a powered-off WLED can't
  hold up a playlist. Results and errors are logged to `logs/plugin-fpp-wled-effects.log`.
- After editing presets in the WLED UI, press **Refresh** on the WLED Effects page. A command that names a
  preset or effect the plugin hasn't seen yet re-reads that controller automatically.

## How it works

| Path | Purpose |
| --- | --- |
| `commands/descriptions.json` | Registers the `WLED - …` FPP Commands. Their dropdowns load from the plugin API. |
| `commands/*.sh` → `lib/cli.php` | What fppd runs for each command. |
| `api.php` | REST API at `/api/plugin/fpp-wled-effects/…` used by the page and the command dropdowns. |
| `lib/wled.php` | Device storage, discovery, WLED JSON API client, name resolution and the actions. |
| `wled.php`, `js/fpp-wled-effects.js` | The WLED Effects page. |
| `help.php` | Help > WLED Effects. |

The device list and the lists read from each controller are stored in
`<media>/plugindata/fpp-wled-effects/`. The plugin talks only to FPP's own API on the player and to WLED's
JSON API (`/json`, `/json/state`, `/presets.json`) on your controllers.

### REST API

All under `/api/plugin/fpp-wled-effects/`:

| Method | Route | |
| --- | --- | --- |
| GET | `devices[?sync=1]` | Devices with status and counts. `sync=1` auto-imports and reads new devices. |
| POST | `devices` | Add `{host, name?, force?}`. |
| POST | `devices/:id` | Edit `{name?, host?, enabled?}`. |
| DELETE | `devices/:id` | Remove. |
| GET | `devices/:id/details` | Effects, palettes, presets and playlists read from the device. |
| POST | `refresh` | Re-read `{id?}` or every device. |
| GET | `discover` | WLED controllers FPP knows about. |
| POST | `settings` | `{autoImport}`. |
| POST | `run` | Run a command now: `{command: "WLED - Apply Preset", args: [...]}`; returns per-device results. |
| GET | `list/devices`, `list/presets`, `list/playlists`, `list/effects`, `list/palettes` | Command dropdown contents. |

## Development

```sh
tests/run.sh --docker   # unit + integration tests against mock WLED controllers and a mock FPP API
```

## License

GPL-2.0, see [LICENSE](LICENSE).
