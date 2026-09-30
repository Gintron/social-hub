  /* The picture is a tile of a leaflet, an object of its own: shown whole, never stretched. */
  .leaflet { position: relative; width: 100%; height: 100%; background: #fff; overflow: hidden; border-radius: 30px; box-shadow: 0 26px 60px rgba(0,0,0,.28); }
  .leaflet__fill { position: absolute; left: -60px; top: -60px; width: calc(100% + 120px); height: calc(100% + 120px); object-fit: cover; filter: blur(30px) saturate(1.1); opacity: .5; }
  .leaflet__img { position: relative; display: block; width: 100%; height: 100%; object-fit: contain; padding: 14px; }
  /* Cut at the bottom (TemplateData::LEAFLET_CUT), where the next tile's fragments sit, and faded into the edge. */
  .leaflet__img--cut { object-fit: cover; object-position: 50% 0; padding: 0; -webkit-mask-image: linear-gradient(180deg, #000 0, #000 86%, transparent 100%); }
