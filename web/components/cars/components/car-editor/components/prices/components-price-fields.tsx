import { getTranslation } from "@/i18n";
import UInput from "@/components/u-input";
import { PriceFieldsProps } from "./model/price.model";

export default function ComponentsPriceFields({
    register,
    control,
    disabled = false,
    setValue,
    watch
}: PriceFieldsProps) {

    // Translate
    const { t } = getTranslation();

    return (
        <>
            <UInput
                name="price_gross"
                type="number"
                label={t('grid.price_gross')}
                register={register}
                className="col-span-12 md:col-span-3"
                min="0"
                step="0.01"
            />
            <UInput
                name="price_net"
                type="number"
                label={t('grid.price_net')}
                register={register}
                className="col-span-12 md:col-span-3"
                min="0"
                step="0.01"
            />
            <UInput
                name="hide_price_online"
                type="checkbox"
                label={t('grid.hide_price_online')}
                register={register}
                className="col-span-12 md:col-span-3"
                min="0"
                step="0.01"
            />
        </>
    )
}
