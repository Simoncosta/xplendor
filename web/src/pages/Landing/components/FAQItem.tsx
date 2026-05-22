import React, { useState } from 'react';
import { Collapse } from 'reactstrap';
import { FAQEntry } from '../data/faqs';

interface FAQItemProps {
    entry: FAQEntry;
}

const FAQItem: React.FC<FAQItemProps> = ({ entry }) => {
    const [open, setOpen] = useState(false);

    return (
        <div className="lp-faq-item">
            <button
                type="button"
                className="lp-faq-question"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
            >
                {entry.question}
                <i
                    className={open ? 'ri-subtract-line' : 'ri-add-line'}
                    aria-hidden="true"
                />
            </button>
            <Collapse isOpen={open}>
                <p className="lp-faq-answer">{entry.answer}</p>
            </Collapse>
        </div>
    );
};

export default FAQItem;
