import {
  AfterViewInit,
  Component,
  ElementRef,
  Input,
  OnChanges,
  OnDestroy,
  SimpleChanges,
  ViewChild,
} from '@angular/core';
import {
  BarController,
  BarElement,
  CategoryScale,
  Chart,
  ChartConfiguration,
  Filler,
  LinearScale,
  LineController,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js';

Chart.register(
  LineController,
  LineElement,
  PointElement,
  Filler,
  BarController,
  BarElement,
  CategoryScale,
  LinearScale,
  Tooltip,
);

/**
 * Thin wrapper that owns the Chart.js instance for a single <canvas>.
 * Recreates the chart when `config` changes — Chart.js handles per-frame
 * updates internally, so this is good enough for our refresh frequency.
 */
@Component({
  selector: 'app-chart-canvas',
  standalone: true,
  template: `<canvas #canvas></canvas>`,
  styles: [
    `
      /*
       * The host IS the relative, fixed-height container Chart.js sizes
       * against — height comes from the consumer's frame (height: 100% here),
       * never from the canvas. The canvas carries NO width/height/position of
       * its own so Chart.js (responsive + maintainAspectRatio:false) can fit
       * it to this box instead of overflowing onto whatever follows.
       */
      :host {
        display: block;
        position: relative;
        width: 100%;
        height: 100%;
        min-width: 0;
      }
      canvas {
        display: block;
      }
    `,
  ],
})
export class ChartCanvasComponent implements AfterViewInit, OnChanges, OnDestroy {
  @ViewChild('canvas', { static: true }) canvasRef!: ElementRef<HTMLCanvasElement>;
  @Input({ required: true }) config!: ChartConfiguration;

  private chart: Chart | null = null;

  ngAfterViewInit(): void {
    this.render();
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['config'] && this.canvasRef) {
      this.render();
    }
  }

  ngOnDestroy(): void {
    this.chart?.destroy();
    this.chart = null;
  }

  private render(): void {
    this.chart?.destroy();
    if (!this.canvasRef?.nativeElement) return;
    this.chart = new Chart(this.canvasRef.nativeElement, this.config);
  }
}
