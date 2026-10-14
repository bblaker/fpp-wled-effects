<?php
/*
 * fpp-wled-effects main page (Content Setup > WLED Effects).
 * Rendered inside FPP's page wrapper; js/fpp-wled-effects.js drives it.
 */
?>
<div id="wledApp" data-plugin="<?= htmlspecialchars($pluginName, ENT_QUOTES, 'UTF-8') ?>">
    <p class="text-body-secondary">
        Control the presets, playlists and effects that live on your WLED controllers from FPP.
        Everything here is also available as <strong>FPP Commands</strong> (named <em>WLED - &hellip;</em>),
        so you can put them in FPP playlists, the scheduler, command presets and buttons.
    </p>

    <div id="wledEmpty" class="alert alert-info d-none" role="status"></div>

    <section class="card mb-3" aria-labelledby="wledDevicesTitle">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h2 id="wledDevicesTitle" class="h5 mb-0 me-auto">WLED Devices</h2>
            <button type="button" class="buttons btn-outline-secondary" id="wledDiscoverBtn">
                <i class="fas fa-search"></i> Find WLED Devices
            </button>
            <button type="button" class="buttons btn-outline-secondary" id="wledRefreshAllBtn">
                <i class="fas fa-sync-alt"></i> Refresh All
            </button>
            <button type="button" class="buttons btn-outline-success" data-bs-toggle="collapse"
                data-bs-target="#wledAddForm" aria-expanded="false" aria-controls="wledAddForm">
                <i class="fas fa-plus"></i> Add Device
            </button>
        </div>
        <div class="card-body">
            <form id="wledAddForm" class="collapse mb-3" autocomplete="off">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-5">
                        <label class="form-label" for="wledAddHost">IP address or hostname</label>
                        <input type="text" class="form-control" id="wledAddHost" placeholder="192.168.1.50" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="wledAddName">Name <span class="text-body-secondary">(optional)</span></label>
                        <input type="text" class="form-control" id="wledAddName" placeholder="Use the name set in WLED">
                    </div>
                    <div class="col-12 col-md-3">
                        <button type="submit" class="buttons btn-success w-100" id="wledAddBtn">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </div>
            </form>

            <div id="wledDiscoverResults" class="d-none mb-3"></div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" id="wledDeviceTable">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col" class="d-none d-sm-table-cell">Address</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="d-none d-lg-table-cell">Firmware</th>
                            <th scope="col" class="d-none d-md-table-cell">Effects</th>
                            <th scope="col" class="d-none d-md-table-cell">Presets</th>
                            <th scope="col" class="d-none d-md-table-cell">Playlists</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="8" class="text-body-secondary">Loading&hellip;</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="form-check form-switch mt-3">
                <input class="form-check-input" type="checkbox" role="switch" id="wledAutoImport">
                <label class="form-check-label" for="wledAutoImport">
                    Automatically add WLED controllers FPP already knows about
                    (from FPP MultiSync discovery and your E1.31/DDP/ArtNet channel outputs)
                </label>
            </div>
        </div>
    </section>

    <section class="card mb-3" aria-labelledby="wledControlTitle" id="wledControl">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h2 id="wledControlTitle" class="h5 mb-0 me-auto">Control</h2>
            <label for="wledTarget" class="mb-0">Device</label>
            <select id="wledTarget" class="form-select w-auto mw-100"></select>
        </div>
        <div class="card-body">
            <ul class="nav nav-pills pageContent-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="wledTabPresetsBtn" data-bs-toggle="pill" data-bs-target="#wledTabPresets"
                        type="button" role="tab" aria-controls="wledTabPresets" aria-selected="true">Presets</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="wledTabPlaylistsBtn" data-bs-toggle="pill" data-bs-target="#wledTabPlaylists"
                        type="button" role="tab" aria-controls="wledTabPlaylists" aria-selected="false">Playlists</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="wledTabEffectsBtn" data-bs-toggle="pill" data-bs-target="#wledTabEffects"
                        type="button" role="tab" aria-controls="wledTabEffects" aria-selected="false">Effects</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="wledTabPowerBtn" data-bs-toggle="pill" data-bs-target="#wledTabPower"
                        type="button" role="tab" aria-controls="wledTabPower" aria-selected="false">Power &amp; Live Data</button>
                </li>
            </ul>

            <div class="tab-content pt-3">
                <div class="tab-pane fade show active" id="wledTabPresets" role="tabpanel" aria-labelledby="wledTabPresetsBtn">
                    <input type="search" class="form-control mb-2" id="wledPresetFilter" placeholder="Filter presets"
                        aria-label="Filter presets">
                    <div class="list-group" id="wledPresetList"></div>
                </div>

                <div class="tab-pane fade" id="wledTabPlaylists" role="tabpanel" aria-labelledby="wledTabPlaylistsBtn">
                    <p class="text-body-secondary small mb-2">
                        WLED playlists run on the controller using the durations saved in WLED.
                    </p>
                    <input type="search" class="form-control mb-2" id="wledPlaylistFilter" placeholder="Filter playlists"
                        aria-label="Filter playlists">
                    <div class="list-group" id="wledPlaylistList"></div>
                </div>

                <div class="tab-pane fade" id="wledTabEffects" role="tabpanel" aria-labelledby="wledTabEffectsBtn">
                    <form id="wledEffectForm" class="row g-3" autocomplete="off">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="wledFx">Effect</label>
                            <select class="form-select" id="wledFx"></select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="wledPal">Palette</label>
                            <select class="form-select" id="wledPal"></select>
                        </div>
                        <div class="col-12 col-md-6">
                            <span class="form-label d-block mb-2">Colors</span>
                            <div class="d-flex flex-wrap gap-3">
                                <label class="d-flex align-items-center gap-2 mb-0">
                                    <input type="color" class="form-control form-control-color" id="wledCol1" value="#ffaa00">1
                                </label>
                                <label class="d-flex align-items-center gap-2 mb-0">
                                    <input type="color" class="form-control form-control-color" id="wledCol2" value="#000000">2
                                </label>
                                <label class="d-flex align-items-center gap-2 mb-0">
                                    <input type="color" class="form-control form-control-color" id="wledCol3" value="#000000">3
                                </label>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="wledSeg">Segments</label>
                            <select class="form-select" id="wledSeg">
                                <option value="Selected">Selected in WLED</option>
                                <option value="All">All segments</option>
                                <?php for ($i = 0; $i < 16; $i++): ?>
                                    <option value="<?= $i ?>">Segment <?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="wledSx">Speed <output id="wledSxOut" for="wledSx">128</output></label>
                            <input type="range" class="form-range" id="wledSx" min="0" max="255" value="128">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="wledIx">Intensity <output id="wledIxOut" for="wledIx">128</output></label>
                            <input type="range" class="form-range" id="wledIx" min="0" max="255" value="128">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="wledFxBri">Brightness <output id="wledFxBriOut" for="wledFxBri">unchanged</output></label>
                            <input type="range" class="form-range" id="wledFxBri" min="0" max="255" value="0">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="wledFxTakeover">
                                <label class="form-check-label" for="wledFxTakeover">
                                    Override FPP live data (show this even while FPP is streaming pixels to this WLED)
                                </label>
                            </div>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button type="submit" class="buttons btn-success"><i class="fas fa-play"></i> Run Now</button>
                            <button type="button" class="buttons btn-outline-primary" id="wledFxAddBtn">
                                <i class="fas fa-list"></i> Add to FPP Playlist
                            </button>
                        </div>
                    </form>
                </div>

                <div class="tab-pane fade" id="wledTabPower" role="tabpanel" aria-labelledby="wledTabPowerBtn">
                    <div class="row g-4">
                        <div class="col-12 col-lg-4">
                            <h3 class="fs-6 fw-semibold">Power</h3>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="buttons btn-outline-success" data-wled-power="On">On</button>
                                <button type="button" class="buttons btn-outline-secondary" data-wled-power="Off">Off</button>
                                <button type="button" class="buttons btn-outline-secondary" data-wled-power="Toggle">Toggle</button>
                            </div>
                            <div class="dropdown mt-2">
                                <button type="button" class="buttons btn-outline-primary dropdown-toggle"
                                    data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-list"></i> Add to FPP Playlist
                                </button>
                                <ul class="dropdown-menu">
                                    <li><button type="button" class="dropdown-item" data-wled-power-add="On">Power On</button></li>
                                    <li><button type="button" class="dropdown-item" data-wled-power-add="Off">Power Off</button></li>
                                    <li><button type="button" class="dropdown-item" data-wled-power-add="Toggle">Power Toggle</button></li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-12 col-lg-4">
                            <h3 class="fs-6 fw-semibold"><label for="wledBri" class="mb-0">Brightness <output id="wledBriOut" for="wledBri">128</output></label></h3>
                            <input type="range" class="form-range" id="wledBri" min="0" max="255" value="128">
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="buttons btn-success" id="wledBriBtn">Set</button>
                                <button type="button" class="buttons btn-outline-primary" id="wledBriAddBtn">
                                    <i class="fas fa-list"></i> Add to FPP Playlist
                                </button>
                            </div>
                        </div>
                        <div class="col-12 col-lg-4">
                            <h3 class="fs-6 fw-semibold"><label for="wledLor" class="mb-0">Override FPP Live Data</label></h3>
                            <select class="form-select mb-2" id="wledLor">
                                <option value="Off">Off &ndash; FPP data has priority</option>
                                <option value="Until Live Data Ends">Until live data ends</option>
                                <option value="Until Reboot">Until WLED reboots</option>
                            </select>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="buttons btn-success" id="wledLorBtn">Apply</button>
                                <button type="button" class="buttons btn-outline-primary" id="wledLorAddBtn">
                                    <i class="fas fa-list"></i> Add to FPP Playlist
                                </button>
                            </div>
                            <p class="small text-body-secondary mt-2 mb-0">
                                When FPP streams E1.31/DDP/ArtNet to a WLED, the live data wins over WLED's own effects.
                                Override lets WLED effects show anyway.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="modal fade" id="wledPlaylistModal" tabindex="-1" aria-labelledby="wledPlaylistModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="wledPlaylistForm" autocomplete="off">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="wledPlaylistModalTitle">Add to FPP Playlist</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        Adds an FPP Command entry: <code id="wledPlEntrySummary"></code>
                    </p>
                    <div class="mb-3">
                        <label class="form-label" for="wledPlName">Playlist</label>
                        <select class="form-select" id="wledPlName"></select>
                    </div>
                    <div class="mb-3 d-none" id="wledPlNewWrap">
                        <label class="form-label" for="wledPlNewName">New playlist name</label>
                        <input type="text" class="form-control" id="wledPlNewName" maxlength="64">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="wledPlSection">Section</label>
                        <select class="form-select" id="wledPlSection">
                            <option value="mainPlaylist">Main Playlist</option>
                            <option value="leadIn">Lead In</option>
                            <option value="leadOut">Lead Out</option>
                        </select>
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="wledPlHold">Hold for (seconds)</label>
                        <input type="number" class="form-control" id="wledPlHold" min="0" max="86400" step="1" value="0">
                    </div>
                    <p class="small text-body-secondary mb-0">
                        FPP Commands finish instantly. A hold time adds a Pause entry after the command so the
                        WLED look stays up for that long before the playlist moves on. 0 adds no pause.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="buttons btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="buttons btn-success" id="wledPlSubmit"><i class="fas fa-plus"></i> Add</button>
                </div>
            </form>
        </div>
    </div>
</div>
