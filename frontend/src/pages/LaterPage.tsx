export function LaterPage({ eyebrow, title, text }: { eyebrow: string; title: string; text: string }) {
  return (
    <section>
      <div className="heading">
        <div>
          <div className="eyebrow">{eyebrow}</div>
          <h1>{title}</h1>
        </div>
      </div>
      <div className="panel">
        <div className="panel-inner"><div className="note">{text}</div></div>
      </div>
    </section>
  )
}
