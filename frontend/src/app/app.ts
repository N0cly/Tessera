import { Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';

import { AppConfigService } from './core/app-config.service';
import { DemoBannerComponent } from './core/demo-banner';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, DemoBannerComponent],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  readonly config = inject(AppConfigService);
}
