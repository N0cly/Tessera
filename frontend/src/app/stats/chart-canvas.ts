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
      :host {
        display: block;
        position: relative;
        width: 100%;
      }
      canvas {
        width: 100% !important;
        height: 100% !important;
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
