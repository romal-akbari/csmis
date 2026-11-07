"use client";

import { useEffect, useId, useRef, useState } from "react";
import { countLabel, dateLabel, stockLabels } from "@/lib/overview";
import type { Overview, TrendDay } from "@/lib/overview";
import { overviewText } from "@/lib/overview-i18n";
import styles from "./overview.module.css";

export function ActivityChart({
  trend, locale, days, onDays, busy,
}: {
  trend: TrendDay[]; locale: string; days: number;
  onDays: (days: number) => void; busy: boolean;
}) {
  const t = (text: string) => overviewText(locale, text);
  const id = useId();
  const plot = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(710);
  useEffect(() => {
    if (!plot.current) return;
    const observer = new ResizeObserver(([entry]) => {
      setWidth(Math.max(240, Math.round(entry.contentRect.width)));
    });
    observer.observe(plot.current);
    return () => observer.disconnect();
  }, []);
  const [selected, setSelected] = useState<number | null>(null);
  const active = Math.min(selected ?? trend.length - 1, trend.length - 1);
  const point = trend[active];
  const maximum = Math.max(4, ...trend.flatMap((d) => [(d.produced ?? 0), d.received]));
  const ceiling = Math.ceil(maximum / 4) * 4;
  const left = 32;
  const right = width - 12;
  const dateTicks = width < 450 ? [0, 2, 4] : [0, 1, 2, 3, 4];
  const x = (index: number) => left + (index * (right - left)) / (trend.length - 1 || 1);
  const y = (value: number) => 178 - (value / ceiling) * 148;
  const line = (key: "produced" | "received") => trend.map((day, i) =>
    `${i ? "L" : "M"}${x(i)},${y(day[key] ?? 0)}`).join(" ");
  const produced = trend.reduce((sum, day) => sum + (day.produced ?? 0), 0);
  const received = trend.reduce((sum, day) => sum + day.received, 0);
  const hasData = produced + received > 0;
  const inspected = point
    ? `${dateLabel(point.date, locale)} · ${t("Produced")} ${countLabel((point.produced ?? 0), locale)} · ${t("Received")} ${countLabel(point.received, locale)}`
    : "";
  return (
    <section className={`${styles.panel} ${styles.activity}`} aria-busy={busy}>
      <div className={styles.panelHeading}>
        <div><h2>{t("Carpet activity")}</h2>
          <p>{t("Production completions and receipts from washing, by day")}</p></div>
        <div className={styles.periods} role="group" aria-label={t("Activity period")}>
          {[7, 30, 90].map((period) => (
            <button key={period} type="button" disabled={busy}
              aria-pressed={days === period} onClick={() => onDays(period)}>
              {t(`${period} days`)}
            </button>
          ))}
        </div>
      </div>
      <div className={styles.chartTotals}>
        <span><i className={styles.purchaseKey} />{t("Produced")}
          <strong>{countLabel(produced, locale)}</strong></span>
        <span><i className={styles.receiptKey} />{t("Received")}
          <strong>{countLabel(received, locale)}</strong></span>
      </div>
      <div className={styles.plot} dir="ltr" ref={plot}>
        <svg viewBox={`0 0 ${width} 212`} role="img" aria-labelledby={`${id}-title`}
          onMouseMove={(event) => {
            const box = event.currentTarget.getBoundingClientRect();
            const position = ((event.clientX - box.left) / box.width) * width;
            setSelected(Math.max(0, Math.min(trend.length - 1,
              Math.round(((position - left) / (right - left)) * (trend.length - 1)))));
          }}>
          <title id={`${id}-title`}>{t("Carpet activity")}</title>
          <defs><linearGradient id={`${id}-fill`} x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stopColor="#203d54" stopOpacity=".16" />
            <stop offset="100%" stopColor="#203d54" stopOpacity="0" />
          </linearGradient></defs>
          {[0, 1, 2, 3, 4].map((tick) => (
            <g key={tick}>
              <line x1={left} x2={right} y1={y((ceiling * tick) / 4)}
                y2={y((ceiling * tick) / 4)} className={styles.gridLine} />
              <text x="23" y={y((ceiling * tick) / 4) + 4} textAnchor="end">
                {countLabel((ceiling * tick) / 4, locale)}</text>
            </g>
          ))}
          {hasData && <>
            <path d={`${line("produced")} L${right},178 L${left},178 Z`}
              fill={`url(#${id}-fill)`} />
            <path d={line("produced")} className={styles.purchaseLine} />
            <path d={line("received")} className={styles.receiptLine} />
            {point && <g>
              <line x1={x(active)} x2={x(active)} y1="26" y2="178"
                className={styles.crosshair} />
              <circle cx={x(active)} cy={y((point.produced ?? 0))} r="4"
                className={styles.purchasePoint} />
              <circle cx={x(active)} cy={y(point.received)} r="4"
                className={styles.receiptPoint} />
            </g>}
          </>}
          {dateTicks.map((tick) => {
            const index = Math.round(((trend.length - 1) * tick) / 4);
            return trend[index] && <text key={tick} x={x(index)} y="204"
              textAnchor={tick === 0 ? "start" : tick === 4 ? "end" : "middle"}>
              {dateLabel(trend[index].date, locale)}</text>;
          })}
        </svg>
        {!hasData && <div className={styles.chartEmpty} dir={locale === "fa" ? "rtl" : "ltr"}>
          <strong>{t("No production or receipts in this period")}</strong>
          <span>{t("Activity appears when completed carpets go to washing and return to stock.")}</span>
        </div>}
      </div>
      {hasData && <div className={styles.inspector}>
        <output>{inspected}</output>
        <input type="range" dir="ltr" min="0" max={trend.length - 1} value={active}
          aria-label={t("Inspect activity day")} aria-valuetext={inspected}
          onChange={(event) => setSelected(Number(event.target.value))} />
      </div>}
      <details className={styles.chartData}>
        <summary>{t("View chart data")}</summary>
        <p>{t("Activity follows the carpets currently in your branches.")}</p>
        <div className={styles.dataScroll}><table>
          <caption className={styles.srOnly}>{t("Carpet activity")}</caption>
          <thead><tr>{["Date", "Produced", "Received"].map((label) =>
            <th key={label} scope="col">{t(label)}</th>)}</tr></thead>
          <tbody>{trend.map((day) => <tr key={day.date}>
            <td>{dateLabel(day.date, locale)}</td>
            <td>{countLabel((day.produced ?? 0), locale)}</td>
            <td>{countLabel(day.received, locale)}</td>
          </tr>)}</tbody>
        </table></div>
      </details>
    </section>
  );
}

export function StockChart({ stock, locale }: {
  stock: NonNullable<Overview["stock"]>; locale: string;
}) {
  const t = (text: string) => overviewText(locale, text);
  const entries = Object.entries(stock.counts) as [keyof typeof stock.counts, number][];
  const total = entries.reduce((sum, [, value]) => sum + value, 0);
  const circumference = 2 * Math.PI * 68;
  let offset = 0;
  return <section className={`${styles.panel} ${styles.stock}`}>
    <div className={styles.panelHeading}><div>
      <h2>{t("Stock overview")}</h2><p>{t("Current carpet status")}</p>
    </div><span className={styles.panelMark} aria-hidden="true">✧</span></div>
    <div className={styles.ringWrap}>
      <svg viewBox="0 0 180 180" aria-hidden="true" className={styles.ring}>
        <circle cx="90" cy="90" r="68" className={styles.ringTrack} />
        {entries.map(([key, value], index) => {
          const length = total ? (value / total) * circumference : 0;
          const position = offset;
          offset += length;
          return value > 0 && <circle key={key} cx="90" cy="90" r="68"
            className={styles[`tone${index}`]} strokeDasharray={`${Math.max(0, length - 3)} ${circumference}`}
            strokeDashoffset={-position} transform="rotate(-90 90 90)" />;
        })}
      </svg>
      <div className={styles.ringCenter}><strong>{countLabel(total, locale)}</strong>
        <span>{t("Tracked carpets")}</span></div>
    </div>
    <ul className={styles.stockLegend} aria-label={t("Current carpet status")}>
      {entries.map(([key, value], index) => <li key={key}>
        <span><i className={styles[`tone${index}`]} />{t(stockLabels[key])}</span>
        <strong>{countLabel(value, locale)}</strong>
      </li>)}
    </ul>
    {total === 0 && <p className={styles.emptyNote}>{t("No carpets registered yet")}</p>}
  </section>;
}
