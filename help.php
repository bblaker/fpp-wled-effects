<div class="container-fluid px-0">
    <h2 class="h4">WLED Effects</h2>
    <p>
        Makes FPP the one place you control your WLED controllers from. The presets, playlists and effects you
        have saved on each WLED become FPP Commands, so you can schedule and sequence them with the rest of the show
        without building xLights sequences for them.
    </p>

    <h3 class="h5 mt-4">1. Add your WLED controllers</h3>
    <p>
        Open <a href="plugin.php?_menu=content&amp;plugin=fpp-wled-effects&amp;page=wled.php">Content Setup &gt; WLED Effects</a>.
        WLED controllers FPP already knows about are added automatically: the ones FPP's MultiSync discovery found
        (Status/Control &gt; MultiSync) and the ones your E1.31, DDP or ArtNet channel outputs send to. Use
        <strong>Find WLED Devices</strong> to look again, or <strong>Add Device</strong> to enter one by IP address or
        hostname. Turn off <em>Automatically add</em> if you'd rather pick them yourself.
    </p>
    <p>
        The plugin reads each controller's effects, palettes, presets and playlists. After changing presets in the
        WLED UI, press <strong>Refresh</strong> so FPP's lists catch up. Commands that name a preset or effect the
        plugin hasn't seen yet re-read the controller automatically.
    </p>

    <h3 class="h5 mt-4">2. Try things out</h3>
    <p>
        The <strong>Control</strong> panel applies presets, starts WLED playlists, runs effects with your choice of
        palette, colors, speed and intensity, and sets power and brightness. Pick one device or
        <em>All WLED Devices</em>.
    </p>

    <h3 class="h5 mt-4">3. Put WLED in FPP playlists and schedules</h3>
    <p>Every action is an FPP Command:</p>
    <ul>
        <li><strong>WLED - Apply Preset</strong>: apply a saved preset by name.</li>
        <li><strong>WLED - Start Playlist</strong>: start a playlist saved in WLED.</li>
        <li><strong>WLED - Run Effect</strong>: run an effect with palette, colors, speed, intensity and brightness.</li>
        <li><strong>WLED - Power</strong>: on, off or toggle.</li>
        <li><strong>WLED - Brightness</strong>: set the master brightness (0 turns the output off).</li>
        <li><strong>WLED - Live Override</strong>: let WLED effects show over pixel data FPP is streaming to it.</li>
    </ul>
    <p>
        Use them anywhere FPP takes a command: an <em>FPP Command</em> entry in a playlist, a Command entry in the
        scheduler, command presets, GPIO inputs and so on. The <strong>Add to FPP Playlist</strong> buttons on the
        WLED Effects page create the playlist entry for you, in an existing playlist or a new one.
    </p>
    <p>
        Commands finish instantly, so in a playlist follow one with a <em>Pause</em> entry for as long as you want the
        look to stay up (the Add to FPP Playlist dialog can add it for you with <em>Hold for</em>). Then schedule the
        playlist as usual.
    </p>

    <h3 class="h5 mt-4">Good to know</h3>
    <ul>
        <li>
            Commands refer to devices, presets and effects <strong>by name</strong>. If you rename one, update the
            playlist entries that use it. With <em>All WLED Devices</em>, every controller that has a preset with
            that name applies it, which is handy when several controllers share preset names.
        </li>
        <li>
            If FPP is streaming pixel data to a WLED (E1.31/DDP/ArtNet) while a sequence plays, WLED shows that live
            data instead of its own effects. Tick <em>Override FPP Live Data</em> on the command, or use
            <em>WLED - Live Override</em>, to show WLED's effect anyway. WLED goes back to its effect on its own a
            few seconds after FPP stops sending.
        </li>
        <li>
            WLED ignores a request to apply the preset that is already active. Apply something else first if you
            need to restart one.
        </li>
        <li>
            Commands give up on a controller that doesn't answer within about 3 seconds so a powered-off WLED can't
            hold up the show. Results and errors go to the <code>plugin-fpp-wled-effects.log</code> log
            (Content Setup &gt; File Manager &gt; Logs).
        </li>
    </ul>

    <h3 class="h5 mt-4">Privacy</h3>
    <p class="mb-0">
        The plugin only talks to FPP on this player and to the WLED controllers you add (or FPP discovers) on your
        own network. Nothing is sent to the internet.
    </p>

    <p class="mt-4 mb-0">
        Source and issues: <a href="https://github.com/bblaker/fpp-wled-effects" target="_blank" rel="noopener">github.com/bblaker/fpp-wled-effects</a>
    </p>
</div>
